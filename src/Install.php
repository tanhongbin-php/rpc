<?php
declare(strict_types=1);

namespace Thb\Rpc;

class Install
{
    public const WEBMAN_PLUGIN = true;

    protected static array $pathRelation = [
        'config/plugin/thb/rpc' => 'config/plugin/thb/rpc',
    ];

    public static function install(): void
    {
        static::installByRelation();
    }

    public static function uninstall(): void
    {
        static::uninstallByRelation();
    }

    protected static function installByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            $parent = base_path() . '/' . dirname($dest);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            copy_dir(__DIR__ . "/../$source", base_path() . "/$dest");
        }
    }

    protected static function uninstallByRelation(): void
    {
        foreach (static::$pathRelation as $dest) {
            $path = base_path() . "/$dest";
            // 保留用户配置，不自动删除
            unset($path);
        }
    }
}
