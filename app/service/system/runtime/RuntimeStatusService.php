<?php
declare(strict_types=1);

namespace app\service\system\runtime;

use app\common\constant\ReceiptRuntimeConstant;

use RuntimeException;
use think\App;
use think\Cache;

/** 工作者及已认证客户端的运行快照；心跳不证明支付应用登录或实际收款能力。 */
final class RuntimeStatusService
{
    // 客户端约 60 秒上报，允许三个周期；仅用于运行展示，不控制通道或订单。
    public const LISTENER_TIMEOUT_SECONDS = 180;

    private const SUMMARY_WRITE_INTERVAL_NANOSECONDS = 10_000_000_000;

    /** @var array<string,array{changes:array,due_at:int}> 只缓冲本次活动字段，不携带缓存中的历史错误。 */
    private array $pendingCollections = [];
    /** @var array<string,int> 最近一次写入的单调时间；到期即移除。 */
    private array $collectionWrittenAt = [];

    public function __construct(private readonly App $app, private readonly Cache $cache) {}

    /**
     * 展示快照统一按配置身份隔离；创建时间防止清库后 ID/版本复用，旧版本不会覆盖当前版本。
     * @param array{plugin_code:string,api_config_id:string,config_version:int,config_created_at:string} $configuration
     */
    private function key(string $kind, array $configuration): string
    {
        return implode('|', [
            $kind,
            $configuration['plugin_code'],
            $configuration['api_config_id'],
            $configuration['config_version'],
            $configuration['config_created_at'],
        ]);
    }

    /** 每份配置只覆盖最新心跳，使用服务端接收时间；不写数据库或逐条回调日志。 */
    public function recordListener(array $configuration, int $receivedAt, array $heartbeat): void
    {
        $saved = $this->cache->store('runtime_status')->set(
            $this->key('listener', $configuration),
            [
                'received_at' => $receivedAt,
                'listening' => $heartbeat['listening'],
                'package_name' => $heartbeat['package_name'],
                'device_name' => $heartbeat['device_name'],
                'client' => $heartbeat['client'],
            ],
            0,
        );
        if (!$saved) {
            throw new RuntimeException('监听状态保存失败');
        }
    }

    /** 当前配置以外的快照不当作当前状态，缓存缺失表示尚无上报。 */
    public function listener(array $configuration): ?array
    {
        $state = $this->cache->store('runtime_status')->get($this->key('listener', $configuration));
        return is_array($state) &&
            is_int($state['received_at'] ?? null) &&
            is_bool($state['listening'] ?? null)
            ? $state
            : null;
    }

    /** 缺失即尚无记录，不读取旧位置或其他配置的摘要。 */
    public function collection(array $configuration): array
    {
        $state = $this->cache
            ->store('runtime_status')
            ->get($this->key('collection', $configuration));
        return is_array($state) ? $state : [];
    }

    /**
     * 普通活动合并为每 10 秒一次写入；首次、失败、状态变化及确认到账立即保存。
     * @param array{error:?array,probe:bool,matched:int,limited:bool,duration_ms:int,occurred_at:string} $activity
     */
    public function recordCollection(array $configuration, array $activity): void
    {
        $key = $this->key('collection', $configuration);
        $previous = $this->collection($configuration);
        // 清理后不复用内存中的旧活动，只有本次真实活动可重新建立摘要。
        $changes = $previous === [] ? [] : $this->pendingCollections[$key]['changes'] ?? [];
        $error = $activity['error'];
        $now = $activity['occurred_at'];
        $changes = array_replace($changes, [
            'status' =>
                $error === null
                    ? ReceiptRuntimeConstant::HEALTHY
                    : ReceiptRuntimeConstant::failure($error['reason']),
            'reason_code' => $error['reason'] ?? ($activity['limited'] ? 'limit_reached' : null),
            'last_duration_ms' => $activity['duration_ms'],
            'updated_at' => $now,
            'consecutive_failures' =>
                $error === null ? 0 : (int) ($previous['consecutive_failures'] ?? 0) + 1,
        ]);
        if ($error !== null) {
            $changes['last_error'] = array_diff_key($error, ['retry_after' => true]) + [
                'occurred_at' => $now,
            ];
            $changes['recovered_at'] = null;
        } else {
            if (isset($previous['last_error']) && empty($previous['recovered_at'])) {
                $changes['recovered_at'] = $now;
            }
            $changes['last_verified_at'] = $now;
            if (!$activity['probe']) {
                $changes['last_success_at'] = $now;
                $changes['matched_count'] = $activity['matched'];
            }
        }
        $important =
            $error !== null ||
            $activity['matched'] > 0 ||
            $previous === [] ||
            ($previous['status'] ?? null) !== $changes['status'] ||
            ($previous['reason_code'] ?? null) !== $changes['reason_code'] ||
            (!$activity['probe'] && !isset($previous['last_success_at'])) ||
            ($activity['probe'] && !isset($previous['last_verified_at']));
        $tick = hrtime(true);
        $writtenAt = $this->collectionWrittenAt[$key] ?? null;
        $dueAt = ($writtenAt ?? 0) + self::SUMMARY_WRITE_INTERVAL_NANOSECONDS;
        if ($important || $writtenAt === null || $dueAt <= $tick) {
            if (
                $this->cache
                    ->store('runtime_status')
                    ->set($key, array_replace($previous, $changes), 0)
            ) {
                $this->collectionWrittenAt[$key] = $tick;
                unset($this->pendingCollections[$key]);
                return;
            }
            $dueAt = $tick;
        }
        $this->pendingCollections[$key] = ['changes' => $changes, 'due_at' => $dueAt];
    }

    /** 等待期间/正常退出保存到期字段；已清理的摘要直接丢弃缓冲，不让旧错误重新出现。 */
    public function flushCollections(bool $force = false): void
    {
        $now = hrtime(true);
        foreach ($this->collectionWrittenAt as $key => $writtenAt) {
            if ($now - $writtenAt >= self::SUMMARY_WRITE_INTERVAL_NANOSECONDS) {
                unset($this->collectionWrittenAt[$key]);
            }
        }
        $store = $this->cache->store('runtime_status');
        foreach ($this->pendingCollections as $key => $pending) {
            if (!$force && $pending['due_at'] > $now) {
                continue;
            }
            $state = $store->get($key);
            if (!is_array($state)) {
                unset($this->pendingCollections[$key], $this->collectionWrittenAt[$key]);
            } elseif ($store->set($key, array_replace($state, $pending['changes']), 0)) {
                $this->collectionWrittenAt[$key] = $now;
                unset($this->pendingCollections[$key]);
            }
        }
    }

    /**
     * 无目录、不可读、格式异常或扫描超限均不能宣称正常；不读取文件名白名单以外的内容。
     * @return array{scan_complete:bool,ignored:int,workers:list<array{role:string,label:string,observed_at:?int,recent:bool,status_label:string}>}
     */
    public function workers(): array
    {
        $latest = ['work' => 0, 'watch' => 0];
        $running = ['work' => false, 'watch' => false];
        // 与 CLI 心跳写入使用同一本机 Unix 时间，不参与交易调度。
        $now = time();
        $directory = rtrim($this->app->getRuntimePath(), '/\\') . '/workers';
        $complete = true;
        $ignored = 0;
        if (is_link($directory) || (is_dir($directory) && !is_readable($directory))) {
            $complete = false;
        } elseif (is_dir($directory)) {
            $examined = 0;
            try {
                foreach (new \DirectoryIterator($directory) as $file) {
                    if ($file->isDot()) {
                        continue;
                    }
                    if (++$examined > 2000) {
                        $complete = false;
                        break;
                    }
                    if (
                        $file->isLink() ||
                        !$file->isFile() ||
                        !preg_match(
                            '/^(work|watch)-([a-f0-9]{32})\.json$/D',
                            $file->getFilename(),
                            $match,
                        ) ||
                        $file->getSize() > 4096
                    ) {
                        $ignored++;
                        continue;
                    }
                    $stream = @fopen($file->getPathname(), 'rb');
                    if ($stream === false) {
                        $complete = false;
                        continue;
                    }
                    try {
                        if (!flock($stream, LOCK_SH | LOCK_NB)) {
                            $complete = false;
                            continue;
                        }
                        $contents = @stream_get_contents($stream, 4097);
                        if (!is_string($contents)) {
                            $complete = false;
                            continue;
                        }
                        $data = json_decode($contents, true);
                    } finally {
                        fclose($stream);
                    }
                    if (
                        !is_array($data) ||
                        ($data['role'] ?? null) !== $match[1] ||
                        ($data['owner'] ?? null) !== $match[2] ||
                        !is_int($data['observed_at'] ?? null) ||
                        $data['observed_at'] < 1 ||
                        $data['observed_at'] > $now + 5 ||
                        !is_bool($data['running'] ?? null)
                    ) {
                        $ignored++;
                        continue;
                    }
                    // 保留最近退出时间供运营核对单轮任务；退出记录不能当作存活心跳。
                    $role = $match[1];
                    if ($data['observed_at'] >= $latest[$role]) {
                        $running[$role] =
                            $data['observed_at'] === $latest[$role]
                                ? $running[$role] || $data['running']
                                : $data['running'];
                        $latest[$role] = $data['observed_at'];
                    }
                }
            } catch (\RuntimeException) {
                $complete = false;
            }
        }
        $workers = [];
        foreach (['work' => '通知与订单维护', 'watch' => '流水采集'] as $role => $label) {
            $recent =
                $complete && $running[$role] && $latest[$role] > 0 && $now - $latest[$role] <= 120;
            $workers[] = [
                'role' => $role,
                'label' => $label,
                'observed_at' => $latest[$role] ?: null,
                'recent' => $recent,
                'status_label' => !$complete
                    ? '任务活动记录读取不完整'
                    : ($recent
                        ? '近期有活动'
                        : ($latest[$role] > 0 && !$running[$role]
                            ? '任务已退出'
                            : '暂无近期活动')),
            ];
        }
        return ['scan_complete' => $complete, 'ignored' => $ignored, 'workers' => $workers];
    }
}
