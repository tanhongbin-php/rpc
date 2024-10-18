<?php

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Thb\Rpc\Process;

use support\Log;
use support\Container;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use support\exception\BusinessException;

/**
 * Class Consumer
 * @package process
 */
class Rpc
{
    protected $ipConfig;

    protected $appDir;

    protected $middleware = [];

    protected $request = [];

    /**
     * StompConsumer constructor.
     * @param string $consumer_dir
     */
    public function __construct(string $appDir = '', array $middleware = [])
    {
        $this->appDir = $appDir;
        $this->middleware = $middleware;
    }

    public function onWorkerStart(Worker $worker)
    {
        $this->ipConfig = config('plugin.thb.rpc.app', []);
    }

    public function onConnect(TcpConnection $connection)
    {
//        $log = $connection->getRemoteIp() . ':' . $connection->getRemotePort() . ' onConnect';
//        Log::channel('rpc')->info($log);
        $ipConfig = $this->ipConfig;
        //判断是否开启rpc的ip限制
        $ips = $ipConfig['ip'] ?? [];
        if(!in_array($connection->getRemoteIp(), $ips)){
            $json = ['code' => 301, 'msg' => 'connection not permitted'];
            $connection->send(json_encode($json));
            $connection->close();
            return false;
        }
        $this->request = [
            'ip' => $connection->getRemoteIp(),
            'port' => $connection->getRemotePort(),
        ];
    }

    public function onMessage(TcpConnection $connection, $data)
    {
        static $instances = [];
        static $middlewares = [];

        $logType = 'info';
        try {
            //接受请求数据
            $data = json_decode($data, true);

            if(!is_array($data)){
                throw new BusinessException('parameter exception', 404);
            }

            $class = $this->appDir . '\\' . strtolower(isset($data['app']) ? $data['app'] . '\\' : '') . 'controller\\' . ucwords($data['class'] ?? '') . 'Controller';
            if (!class_exists($class)) {
                throw new BusinessException('class not found', 404);
            }

            $method = $data['method'] ?? '';
            if (!method_exists($class, $method)) {
                throw new BusinessException('method not found',404);
            }

            //验签（ip=客户端ip地址&appSecret=rpc配置的秘钥,最后用md5加密，转大写）
            $ipConfig = $this->ipConfig;
            $dontReport = $ipConfig['dontReport'] ?? [];
            if(!in_array($connection->getRemoteIp(), $dontReport)){
                $sign = $data['sign'] ?? '';
                $signRpc = strtoupper(md5('ip=' . $connection->getRemoteIp() . '&appSecret=' . $ipConfig['appSecret']));
                if($sign != $signRpc){
                    throw new BusinessException('signature verification failed', 401);
                }
            }
            //获取参数
            $args = $data['args'] ?? [];

            if (!isset($instances[$class])) {
                $instances[$class] = new $class; // 缓存类实例，避免重复初始化
            }

            // 使用示例
            $rpc = Container::get('Thb\Rpc\Rpclication');

            foreach ($this->middleware as $middleware) {
                if(!class_exists($middleware)){
                    continue;
                }
                if (isset($middlewares[$middleware])) {
                    continue;
                }
                $middlewares[$middleware] = $middleware; // 缓存中间件类实例，避免重复初始化
                $rpc->use(new $middleware); // 添加中间件
            }

            //请求数据
            $this->request['app_dir'] = $this->appDir;
            $this->request['data'] = $data;

            // 处理请求 输出响应
            $json = $rpc->handle($this->request, function() use($instances, $class, $method, $args) {
                try {
                    return call_user_func_array([$instances[$class], $method], [$args]);
                } catch (BusinessException $exception) {
                    return ['code' => $exception->getCode(), 'msg' => $exception->getMessage()];
                } catch (\Throwable $exception) {
                    return ['code' => 500, 'msg' => ['errMessage'=>$exception->getMessage(), 'errCode'=>$exception->getCode(), 'errFile'=>$exception->getFile(), 'errLine'=>$exception->getLine()]];
                }
            });
            $send = json_encode($json);
        } catch (BusinessException $exception) {
            $json = ['code' => $exception->getCode(), 'msg' => $exception->getMessage()];
            $send = $this->log($connection, $logType, $start_time, $data, $json);
        } catch (\Throwable $exception) {
            $json = ['code' => 501, 'msg' => ['errMessage'=>$exception->getMessage(), 'errCode'=>$exception->getCode(), 'errFile'=>$exception->getFile(), 'errLine'=>$exception->getLine()]];
            $logType = 'error';
            $send = $this->log($connection, $logType, $start_time, $data, $json);
        }
        $connection->send($send);
    }

    public function onClose(TcpConnection $connection)
    {
//        $log = $connection->getRemoteIp() . ':' . $connection->getRemotePort() . ' onClose';
//        Log::channel('rpc')->info($log);
    }

    private function log(object $connection, string $logType, float $start_time, array $data, array $json) : string
    {
        try {
            $log = $connection->getRemoteIp() . ':' . $connection->getRemotePort() . " [rpc/log]";
            Log::channel('plugin.thb.rpc.default')->$logType($log, ['request' => $data, 'response' => $json]);
        } catch (\Throwable $exception) {
            $json = ['code' => 502, 'msg' => $exception->getMessage()];
        }
        return json_encode($json);
    }
}
