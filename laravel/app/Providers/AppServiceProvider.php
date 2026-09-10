<?php

namespace App\Providers;

use App\Support\FileStore;
use App\Support\Paths;
use App\Support\SqliteStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use PDO;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SqliteStore::class, function (): SqliteStore {
            File::ensureDirectoryExists(Paths::dataDir());
            File::ensureDirectoryExists(Paths::filesDir());
            $pdo = new PDO('sqlite:'.Paths::sqlitePath());

            return new SqliteStore($pdo);
        });
        $this->app->singleton(FileStore::class, fn (): FileStore => new FileStore(Paths::filesDir()));
    }

    public function boot(): void
    {
        $this->loadLocalSeedVars();
    }

    private function loadLocalSeedVars(): void
    {
        $path = dirname(base_path()).'/project/.dev.vars';
        if (! is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            if ($key === 'SEED_USER_EMAIL' && ! config('mytickets.seed_email')) {
                config(['mytickets.seed_email' => $value]);
            }
            if ($key === 'SEED_USER_NAME' && ! config('mytickets.seed_name')) {
                config(['mytickets.seed_name' => $value]);
            }
            if ($key === 'SEED_USER_PASSWORD' && ! config('mytickets.seed_password')) {
                config(['mytickets.seed_password' => $value]);
            }
        }
    }
}
