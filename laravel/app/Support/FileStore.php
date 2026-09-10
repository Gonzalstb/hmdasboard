<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;

final class FileStore
{
    public function __construct(private readonly string $root)
    {
        File::ensureDirectoryExists($this->root);
    }

    public function put(string $key, string $body): void
    {
        $target = $this->pathFor($key);
        File::ensureDirectoryExists(dirname($target));
        File::put($target, $body);
    }

    /** @return object{size: int, path: string}|null */
    public function get(string $key): ?object
    {
        $target = $this->pathFor($key);
        if (! is_file($target)) {
            return null;
        }

        return (object) [
            'size' => filesize($target) ?: 0,
            'path' => $target,
        ];
    }

    public function delete(string $key): void
    {
        $target = $this->pathFor($key);
        if (is_file($target)) {
            File::delete($target);
        }
    }

    private function pathFor(string $key): string
    {
        $relative = ltrim($key, '/');
        $full = $this->root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        $root = realpath($this->root) ?: $this->root;
        $parent = dirname($full);
        if (! is_dir($parent)) {
            File::ensureDirectoryExists($parent);
        }
        $resolvedParent = realpath($parent) ?: $parent;
        if (! str_starts_with($resolvedParent, $root)) {
            throw new \RuntimeException('Clave de almacenamiento no válida.');
        }

        return $full;
    }
}
