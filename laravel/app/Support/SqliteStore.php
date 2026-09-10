<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class SqliteStore
{
    public bool $schemaReady = false;

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
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
