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
    }

    public function onMessage(TcpConnection $connection, $data)
    {
        static $instances = [];
        $start_time = microtime(true);
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

            foreach ($this->middleware as $middleware) {
                call_user_func_array([$middleware, 'process'], [$this->appDir, $data]);
            }

//            //表单验证
//            $validateClass = $this->appDir . '\\' . strtolower(isset($data['app']) ? $data['app'] . '\\' : '') . 'validate\\' . strtolower($data['class'] ?? '') . '\\' . ucfirst($data['method']);
//            // Validate class existence
//            if (class_exists($validateClass)) {
//                $validator = new $validateClass;
//                // Perform form data validation
//                if ($validator->switch == 'on' && !$validator->check($args)) {
//                    throw new BusinessException(422,$validator->getError());
//                }
//            }

            if (!isset($instances[$class])) {
                $instances[$class] = new $class; // 缓存类实例，避免重复初始化
            }

            $json = call_user_func_array([$instances[$class], $method], [$args]);
        } catch (BusinessException $exception) {
            $json = ['code' => $exception->getCode(), 'msg' => $exception->getMessage()];
        } catch (\Throwable $exception) {
            $json = ['code' => 500, 'msg' => ['errMessage'=>$exception->getMessage(), 'errCode'=>$exception->getCode(), 'errFile'=>$exception->getFile(), 'errLine'=>$exception->getLine()]];
            $logType = 'error';
        }
        $send = $this->log($connection, $logType, $start_time, $data, $json);
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
            $time_diff = substr(strval((microtime(true) - $start_time) * 1000), 0, 7);
            $log = $connection->getRemoteIp() . ':' . $connection->getRemotePort() . " [{$time_diff}ms] [rpc/log]";
            Log::channel('plugin.thb.rpc.default')->$logType($log, ['request' => $data, 'response' => $json]);
            !envs('APP_DEBUG', false) && $json['msg'] = 'Server internal error';
        } catch (\Throwable $exception) {
            $json = ['code' => 501, 'msg' => $exception->getMessage()];
        }
        return json_encode($json);
    }
}
