<?php
declare(strict_types=1);

namespace Thb\Rpc;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as MonologLogger;

/**
 * 从 config/plugin/thb/rpc/log.php 构建日志通道
 */
class Logger
{
    /** @var array<string, MonologLogger> */
    protected static array $channels = [];

    public static function channel(string $name = 'default'): MonologLogger
    {
        $key = $name;
        if (!isset(static::$channels[$key])) {
            $config = config("plugin.thb.rpc.log.$name") ?: config('plugin.thb.rpc.log.default', []);
            $handlers = [];
            foreach ($config['handlers'] ?? [] as $item) {
                $class = $item['class'] ?? null;
                if (!$class) {
                    continue;
                }
                /** @var HandlerInterface $handler */
                $handler = new $class(...array_values($item['constructor'] ?? []));
                $formatterConfig = $item['formatter'] ?? [];
                if ($handler instanceof FormattableHandlerInterface && !empty($formatterConfig['class'])) {
                    /** @var FormatterInterface $formatter */
                    $formatter = new $formatterConfig['class'](...array_values($formatterConfig['constructor'] ?? []));
                    $handler->setFormatter($formatter);
                }
                $handlers[] = $handler;
            }
            static::$channels[$key] = new MonologLogger('thb.rpc.' . $name, $handlers);
        }
        return static::$channels[$key];
    }
}
