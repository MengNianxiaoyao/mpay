<?php
declare(strict_types=1);

namespace app\service\system\runtime;

use app\common\base\BaseService;
use app\common\util\SafeLogger;
use app\repository\system\AdminOperationLogRepository;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\audit\ConfigurationAuditService;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use think\App;
use think\Config;
use Throwable;

/** 只清理固定类别的可重建缓存；目录取服务端配置，不接触登录、交易保护及业务记录。 */
final class RuntimeCacheService extends BaseService
{
    public function __construct(
        private readonly App $app,
        private readonly Config $config,
        private readonly ConfigurationAuditService $audit,
        private readonly AdminOperationLogRepository $logs,
        private readonly SafeLogger $logger,
    ) {}

    /** @return array{cleared:list<string>,failed:list<string>,deleted_files:int} 文件清理无法回滚，逐类返回实际结果。 */
    public function clear(array $scopes, ConfigurationActionContext $actor): array
    {
        // 先复核管理员并登记请求；文件扫描在事务外，避免长时间持有管理员行锁。
        $this->transaction(function () use ($actor, $scopes): void {
            $this->audit->authorize($actor);
            $this->record($actor, 'started', ['scopes' => $scopes]);
        });
        $result = ['cleared' => [], 'failed' => [], 'deleted_files' => 0];
        $directoriesByScope = [
            'ordinary' => [
                $this->app->getRuntimePath() . 'cache',
                $this->app->getRuntimePath() . 'temp',
            ],
            'runtime_status' => [(string) $this->config->get('cache.stores.runtime_status.path')],
        ];
        foreach ($directoriesByScope as $scope => $directories) {
            if (!in_array($scope, $scopes, true)) {
                continue;
            }
            try {
                foreach ($directories as $directory) {
                    $this->clearDirectory($directory, $result['deleted_files']);
                }
                $result['cleared'][] = $scope;
            } catch (Throwable $error) {
                $result['failed'][] = $scope;
                $this->logger->exception($error);
            }
        }
        $this->transaction(
            fn() => $this->record($actor, $result['failed'] === [] ? 'cleared' : 'failed', $result),
        );
        return $result;
    }

    /** 保留目录避免并发写入时反复建目录；文件已被并发删除视为完成，权限等真实故障必须报告。 */
    private function clearDirectory(string $directory, int &$deleted): void
    {
        $path = rtrim($directory, '/\\');
        if ($path === '') {
            throw new RuntimeException('缓存目录未配置');
        }
        if (is_link(dirname($path)) || is_link($path)) {
            throw new RuntimeException('缓存目录不能是符号链接：' . $directory);
        }
        if (!file_exists($path)) {
            return;
        }
        if (!is_dir($path) || !is_readable($path)) {
            throw new RuntimeException('缓存目录不可读取：' . $directory);
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('缓存目录包含符号链接：' . $directory);
            }
            if ($file->isDir()) {
                continue;
            }
            $filename = $file->getPathname();
            if (@unlink($filename)) {
                $deleted++;
            } else {
                clearstatcache(true, $filename);
                if (file_exists($filename)) {
                    throw new RuntimeException('缓存文件清理失败，请检查目录权限：' . $directory);
                }
            }
        }
    }

    /** 请求和结果共用 request_id，摘要只含固定清理类别、结果及数量。 */
    private function record(ConfigurationActionContext $actor, string $result, array $summary): void
    {
        $this->logs->record([
            'admin_user_id' => $actor->adminId,
            'request_id' => $actor->requestId,
            'action' => 'runtime.cache.clear',
            'target_type' => 'runtime_cache',
            'target_id' => null,
            'result_code' => $result,
            'client_ip' => $actor->clientIp,
            'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
