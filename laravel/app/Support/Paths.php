<?php

declare(strict_types=1);

namespace App\Support;

final class Paths
{
    public static function dataDir(): string
    {
        $configured = config('mytickets.data_dir');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $cwd = getcwd() ?: base_path();
        if (str_contains($cwd, '/hbuilds/') || str_contains($cwd, '\\hbuilds\\')) {
            return dirname($cwd, 3).DIRECTORY_SEPARATOR.'mytickets-data';
        }

        return storage_path('app/mytickets');
    }

    public static function sqlitePath(): string
    {
        return self::dataDir().DIRECTORY_SEPARATOR.(string) config('mytickets.sqlite_file', 'mytickets.sqlite');
    }

    public static function filesDir(): string
    {
        return self::dataDir().DIRECTORY_SEPARATOR.'files';
    }
}
