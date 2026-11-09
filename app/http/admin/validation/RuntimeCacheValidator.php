<?php
declare(strict_types=1);

namespace app\http\admin\validation;

use app\common\constant\ApiResponseConstant;
use app\exception\BusinessException;

/** 只接收两项清理开关，路径和驱动由服务固定，不接收浏览器指定。 */
final class RuntimeCacheValidator
{
    /** @return list<string> */
    public function clear(array $input): array
    {
        if (
            array_diff(array_keys($input), ['ordinary', 'runtime_status']) !== [] ||
            !is_bool($input['ordinary'] ?? null) ||
            !is_bool($input['runtime_status'] ?? null) ||
            (!$input['ordinary'] && !$input['runtime_status'])
        ) {
            throw new BusinessException(
                '请至少选择一类缓存',
                ApiResponseConstant::VALIDATION_FAILED,
            );
        }
        return array_keys(array_filter($input));
    }
}
