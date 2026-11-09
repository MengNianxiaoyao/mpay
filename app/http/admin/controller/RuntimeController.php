<?php
declare(strict_types=1);

namespace app\http\admin\controller;

use app\common\base\BaseAdminController;
use app\http\admin\validation\RuntimeQueryValidator;
use app\http\admin\validation\RuntimeCacheValidator;
use app\service\system\audit\ConfigurationActionContext;
use app\service\system\runtime\RuntimeCacheService;
use app\service\system\runtime\RuntimeQueryService;
use app\service\system\runtime\SystemDoctorService;
use think\Response;

/** 运行诊断和受控缓存清理；不开放任意路径、shell、迁移、进程控制或平台登录。 */
final class RuntimeController extends BaseAdminController
{
    public function clearCache(
        RuntimeCacheValidator $validator,
        RuntimeCacheService $cache,
    ): Response {
        $scopes = $validator->clear($this->jsonBody());
        $identity = $this->identity();
        $actor = ConfigurationActionContext::administrator(
            $identity['id'],
            $identity['session_version'],
            $this->context->id(),
            null,
            (string) $this->request->server('REMOTE_ADDR', ''),
        );
        return $this->success($cache->clear($scopes, $actor));
    }

    public function overview(RuntimeQueryValidator $validator, RuntimeQueryService $query): Response
    {
        $validator->emptyQuery($this->request->get());
        return $this->success($query->overview());
    }

    public function collections(
        RuntimeQueryValidator $validator,
        RuntimeQueryService $query,
    ): Response {
        $input = $validator->collections($this->request->get());
        $data = $query->collections($input['filters'], $input['page'], $input['limit']);
        return $this->page($data['items'], $data['total'], $data['page'], $data['limit']);
    }

    /** 由管理员明确点选再执行较完整的只读结构检查，普通页面刷新不逐表检查迁移。 */
    public function doctor(RuntimeQueryValidator $validator, SystemDoctorService $doctor): Response
    {
        $validator->emptyQuery($this->request->get());
        return $this->success(['sapi' => PHP_SAPI, 'checks' => $doctor->inspect()]);
    }
}
