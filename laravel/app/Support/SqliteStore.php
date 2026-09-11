<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;
use PDO;

final class SqliteStore
{
    public bool $schemaReady = false;

    public function __construct(
        private readonly PDO $pdo,
        public readonly string $driver = 'sqlite',
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        if ($this->isMysql()) {
            $this->pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        } else {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    public static function connect(): self
    {
        $driver = (string) config('database.default', 'sqlite');
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $cfg = config('database.connections.'.$driver);
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                (string) ($cfg['host'] ?? '127.0.0.1'),
                (string) ($cfg['port'] ?? '3306'),
                (string) ($cfg['database'] ?? ''),
                (string) ($cfg['charset'] ?? 'utf8mb4'),
            );
            $pdo = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            return new self($pdo, 'mysql');
        }

        File::ensureDirectoryExists(Paths::dataDir());
        File::ensureDirectoryExists(Paths::filesDir());

        return new self(new PDO('sqlite:'.Paths::sqlitePath()), 'sqlite');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function isMysql(): bool
    {
        return $this->driver === 'mysql';
    }

    public function nowExpr(): string
    {
        return $this->isMysql() ? 'UTC_TIMESTAMP()' : "datetime('now')";
    }

    public function nowPlusDays(int $days): string
    {
        $days = max(0, $days);

        return $this->isMysql()
            ? 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL '.$days.' DAY)'
            : "datetime('now','+".$days." days')";
    }

    public function nowMinusSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return $this->isMysql()
            ? 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL '.$seconds.' SECOND)'
            : "datetime('now','-".$seconds." seconds')";
    }

    public function insertIgnore(): string
    {
        return $this->isMysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    }

    public function upsertMeta(): string
    {
        return $this->isMysql()
            ? 'REPLACE INTO app_meta(`key`,value) VALUES(?,?)'
            : 'INSERT OR REPLACE INTO app_meta(`key`,value) VALUES(?,?)';
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<object>
     */
    public function tableColumns(string $table): array
    {
        if (preg_match('/^[a-z_]+$/', $table) !== 1) {
            throw new \InvalidArgumentException('Tabla no válida.');
        }
        if ($this->isMysql()) {
            $rows = $this->prepare('SHOW COLUMNS FROM `'.$table.'`')->all()->results;

            return array_map(static function (object $row): object {
                $name = (string) ($row->Field ?? $row->field ?? '');

                return (object) ['name' => $name];
            }, $rows);
        }

        return $this->prepare('PRAGMA table_info('.$table.')')->all()->results;
    }

    public function hasColumn(string $table, string $column): bool
    {
        foreach ($this->tableColumns($table) as $entry) {
            if (($entry->name ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    public function prepare(string $sql): BoundStatement
    {
        return new BoundStatement($this->pdo, $sql);
    }

    /**
     * @param  list<BoundStatement>  $statements
     * @return list<object>
     */
    public function batch(array $statements): array
    {
        $this->pdo->beginTransaction();
        try {
            $out = [];
            foreach ($statements as $statement) {
                $out[] = $statement->executeForBatch();
            }
            $this->pdo->commit();

            return $out;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}
