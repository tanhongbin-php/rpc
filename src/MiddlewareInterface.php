<?php

namespace Thb\Rpc;

interface MiddlewareInterface
{
    public function process(array $request, callable $next): mixed;
}
