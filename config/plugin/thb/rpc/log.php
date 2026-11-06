<?php
/**
 * 插件默认日志配置（安装后可改）
 * 使用标准 Monolog，不依赖项目自定义 Handler
 * 仅当方法标注 #[\Thb\Rpc\Attribute\LogWrite] 或发生异常时写入
 */
return [
    'default' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/rpc.log',
                    15,
                    Monolog\Logger::DEBUG,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [
                        null,
                        'Y-m-d H:i:s',
                        true,
                        true,
                    ],
                ],
            ],
        ],
    ],
];
