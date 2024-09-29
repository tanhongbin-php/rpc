<?php

return [
    'rpc'  => [
        'handler' => Thb\Rpc\Process\Rpc::class,
        'listen'  => 'text://0.0.0.0:8888', // 这里用了text协议，也可以用frame或其它协议
        'count'   => 8, // 可以设置多进程
        'reusePort' => true,
        // 进程类构造函数参数，这里为 process\Rpc::class 类的构造函数参数 （可选）
        'constructor' => [
            // app目录
            'appDir' => 'rpc',
            //类似中间件
            'middleware' => []
        ],
    ],
];