<?php

use Thb\Rpc\Process\Rpc;

return [
    'server' => [
        'handler' => Rpc::class,
        'listen' => 'text://0.0.0.0:9512',
        'count' => 4,
        'reloadable' => true,
    ],
];
