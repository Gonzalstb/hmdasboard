<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOStatement;

final class BoundStatement
{
    /** @param list<mixed> $params */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $sql,
        private readonly array $params = [],
    ) {}

    public function bind(mixed ...$params): self
    {
        return new self($this->pdo, $this->sql, $params);
    }

    public function first(): ?object
    {
        $statement = $this->execute();
        $row = $statement->fetch(PDO::FETCH_OBJ);

        return $row === false ? null : $row;
    }

    /** @return object{results: list<object>, success: bool} */
    public function all(): object
    {
        $statement = $this->execute();

        return (object) [
            'success' => true,
            'results' => $statement->fetchAll(PDO::FETCH_OBJ),
        ];
    }

    /** @return object{success: bool, meta: object} */
    public function run(): object
    {
        $statement = $this->execute();

        return (object) [
            'success' => true,
            'results' => [],
            'meta' => (object) [
                'changes' => $statement->rowCount(),
                'last_row_id' => (int) $this->pdo->lastInsertId(),
            ],
        ];
    }

    /** @return object{success: bool, results: list<object>, meta?: object} */
    public function executeForBatch(): object
    {
        $trimmed = ltrim($this->sql);
        if (preg_match('/^(SELECT|WITH|PRAGMA|EXPLAIN|SHOW|DESCRIBE|DESC)\b/i', $trimmed) === 1) {
            return $this->all();
        }

        return $this->run();
    }

    private function execute(): PDOStatement
    {
        $statement = $this->pdo->prepare($this->sql);
        $statement->execute(array_map(
            static fn (mixed $value): mixed => $value === null ? null : $value,
            $this->params,
        ));

        return $statement;
    }
}
