<?php
return [
    'enable' => true,
    //ip限制
    'ip' => [],
    //秘钥
    'appSecret' => '', //加密算法（strtoupper(md5(ip=客户端&appSecret=秘钥))）
    //排除ip验签验证
    'dontReport' => [],
];