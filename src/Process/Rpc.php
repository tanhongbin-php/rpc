<?php
declare(strict_types=1);

namespace Thb\Rpc\Process;

use ReflectionMethod;
use support\exception\BusinessException;
use Thb\Rpc\Attribute\LogWrite;
use Thb\Rpc\Logger;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

/**
 * RPC Text 协议进程
 */
class Rpc
{
    protected array $appConfig = [];

    /** @var array<string, array<string, bool>> class => [method => true] */
    protected static array $logWriteCache = [];

    public function onWorkerStart(Worker $worker): void
    {
        $this->appConfig = config('plugin.thb.rpc.app', []);
    }

    public function onConnect(TcpConnection $connection): void
    {
        $allowIps = $this->appConfig['ip'] ?? [];
        // 空数组 = 不限制；非空则白名单（支持 127.0.*.* / 127.0.0.*）
        if ($allowIps !== [] && !$this->ipAllowed($connection->getRemoteIp(), $allowIps)) {
            $connection->send(json_encode(['code' => 301, 'msg' => 'connection not permitted'], JSON_UNESCAPED_UNICODE));
            $connection->close();
        }
    }

    public function onMessage(TcpConnection $connection, $data): void
    {
        /** @var array<string, object> */
        static $instances = [];

        $start = microtime(true);
        $request = [];
        $logType = 'info';
        $needLog = false;
        $class = '';
        $method = '';
        $exceptionText = '';

        try {
            $decoded = json_decode((string)$data, true);
            if (!is_array($decoded)) {
                throw new BusinessException('parameter exception', 404);
            }
            $request = $decoded;

            $namespace = rtrim((string)($this->appConfig['namespace'] ?? 'rpc'), '\\');
            $className = trim((string)($request['class'] ?? ''), '\\');
            if ($className === '') {
                throw new BusinessException('class not found', 404);
            }

            // 可选 app 子目录：rpc\{app}\Xxx
            $app = trim((string)($request['app'] ?? ''), '\\');
            $class = $app !== ''
                ? $namespace . '\\' . $app . '\\' . $className
                : $namespace . '\\' . $className;

            if (!class_exists($class)) {
                throw new BusinessException('class not found', 404);
            }

            $method = (string)($request['method'] ?? '');
            if ($method === '' || !method_exists($class, $method)) {
                throw new BusinessException('method not found', 404);
            }

            $needLog = $this->methodHasLogWrite($class, $method);

            $args = $request['args'] ?? [];
            if (!is_array($args)) {
                $args = [];
            }
            if (!isset($instances[$class])) {
                $instances[$class] = new $class();
            }

            $result = call_user_func([$instances[$class], $method], ...$args);
            if (is_array($result)) {
                $json = $result;
            } elseif (is_string($result)) {
                $decoded = json_decode($result, true);
                $json = is_array($decoded) ? $decoded : ['code' => 0, 'msg' => 'ok', 'data' => $result];
            } else {
                $json = ['code' => 0, 'msg' => 'ok', 'data' => $result];
            }
        } catch (BusinessException $e) {
            $json = ['code' => $e->getCode() ?: 400, 'msg' => $e->getMessage()];
        } catch (Throwable $e) {
            $json = [
                'code' => 500,
                'msg' => [
                    'errMessage' => $e->getMessage(),
                    'errCode' => $e->getCode(),
                    'errFile' => $e->getFile(),
                    'errLine' => $e->getLine(),
                ],
            ];
            $logType = 'error';
            $needLog = true; // 异常强制记日志
            $exceptionText = (string)$e;
        }

        $payload = $this->finalize(
            $connection,
            $logType,
            $start,
            $request,
            $json,
            $needLog,
            $class,
            $method,
            $exceptionText
        );
        $connection->send($payload);
    }

    public function onClose(TcpConnection $connection): void
    {
    }

    /**
     * IP 白名单匹配，支持 * 通配（如 127.0.*.*、192.168.1.*）
     *
     * @param list<string> $allowIps
     */
    protected function ipAllowed(string $remoteIp, array $allowIps): bool
    {
        $remoteIp = trim($remoteIp);
        foreach ($allowIps as $rule) {
            $rule = trim((string)$rule);
            if ($rule === '') {
                continue;
            }
            if ($rule === $remoteIp) {
                return true;
            }
            // 将 * 转为正则一段 IPv4
            if (str_contains($rule, '*')) {
                $pattern = '/^' . str_replace('\*', '[0-9]{1,3}', preg_quote($rule, '/')) . '$/';
                if (preg_match($pattern, $remoteIp)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function methodHasLogWrite(string $class, string $method): bool
    {
        if (!isset(self::$logWriteCache[$class])) {
            self::$logWriteCache[$class] = [];
            try {
                $ref = new \ReflectionClass($class);
                foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                    if ($m->getDeclaringClass()->getName() !== $ref->getName()) {
                        continue;
                    }
                    if ($m->getAttributes(LogWrite::class)) {
                        self::$logWriteCache[$class][$m->getName()] = true;
                    }
                }
            } catch (Throwable) {
                // ignore
            }
        }
        return !empty(self::$logWriteCache[$class][$method]);
    }

    protected function finalize(
        TcpConnection $connection,
        string $logType,
        float $start,
        array $request,
        array $json,
        bool $needLog,
        string $class,
        string $method,
        string $exceptionText = ''
    ): string {
        try {
            $returnJson = (string)json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($needLog) {
                // 格式对齐 ApiLog：ip / 调用类方法 / 耗时 / PARAMS / RETURN
                $timeDiff = substr(strval((microtime(true) - $start) * 1000), 0, 7);
                $call = ($class !== '' ? $class : '-') . '::' . ($method !== '' ? $method : '-');
                $params = $request['args'] ?? $request;

                $logs = "[{$connection->getRemoteIp()}] [RPC] [{$call}] [{$timeDiff}ms] [rpc/log]" . PHP_EOL;
                $logs .= '[PARAMS] ' . json_encode($params, JSON_UNESCAPED_UNICODE) . PHP_EOL;
                if ($exceptionText !== '') {
                    $logs .= $exceptionText . PHP_EOL;
                }
                $logs .= '[RETURN] ' . $returnJson;

                Logger::channel('default')->{$logType}($logs);
            }
            // 非调试环境隐藏 500 详情
            if (($json['code'] ?? 0) >= 500 && !env('APP_DEBUG', false)) {
                $json['msg'] = 'Server internal error';
                $returnJson = (string)json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return $returnJson;
        } catch (Throwable $e) {
            return (string)json_encode(['code' => 501, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
