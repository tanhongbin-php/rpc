<?php
declare(strict_types=1);

namespace Thb\Rpc\Attribute;

/**
 * 标记 RPC 方法需要记录访问日志
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class LogWrite
{
}
