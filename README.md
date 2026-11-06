# thb/rpc

Webman RPC 插件（Text 协议）。独立进程监听 TCP，按 `class` / `method` / `args` 调用项目内服务类。

## 安装

```bash
composer require thb/rpc
```

安装后会复制配置到 `config/plugin/thb/rpc/`。

## 配置

- `app.php`：`namespace`（默认 `rpc`）、IP 白名单（支持 `127.0.*.*`；空数组不限制）
- `process.php`：监听地址（默认 `text://0.0.0.0:9512`）、进程数
- `log.php`：Monolog 通道（默认 `runtime/logs/rpc.log`）

## 服务类

在项目 `rpc/` 目录下写类，例如：

```php
<?php
namespace rpc;

use Thb\Rpc\Attribute\LogWrite;

class Ceshi
{
    public function ping(): array
    {
        return ['code' => 0, 'msg' => 'pong'];
    }

    #[LogWrite]
    public function ceshi(int $uid): array
    {
        return ['code' => 0, 'msg' => 'ok', 'data' => ['uid' => $uid]];
    }
}
```

`args` 会按命名/位置参数展开（`...$args`）。只有标注 `#[LogWrite]` 的方法（以及未捕获异常）会写日志。

## 调用协议

客户端连接 `tcp://127.0.0.1:9512`，发送一行 JSON + `\n`：

```json
{"class":"Ceshi","method":"ceshi","args":{"uid":1001}}
```

可选字段：`app`（子命名空间，如 `rpc\{app}\Class`）。

返回一行 JSON。

## 要求

- PHP >= 8.1
- webman / workerman
