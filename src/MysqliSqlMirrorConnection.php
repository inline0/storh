<?php

declare(strict_types=1);

namespace Storh;

/**
 * @codeCoverageIgnore Requires a live MySQL server; exercised by the SQL Mirror MySQL CI job.
 */
final class MysqliSqlMirrorConnection implements SqlMirrorConnection
{
    private bool $in_transaction = false;

    public function __construct(private readonly \mysqli $mysqli)
    {
    }

    public function driver(): string
    {
        return 'mysql';
    }

    public function execute(string $sql): void
    {
        try {
            $result = $this->mysqli->query($sql);
        } catch (\mysqli_sql_exception $exception) {
            throw new StorageException('SQL mirror statement failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (false === $result) {
            throw new StorageException('SQL mirror statement failed: ' . $this->mysqli->error);
        }
    }

    /**
     * @return \Generator<int, list<mixed>>
     */
    public function rows(string $sql): \Generator
    {
        try {
            $result = $this->mysqli->query($sql);
        } catch (\mysqli_sql_exception $exception) {
            throw new StorageException('SQL mirror statement failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (! $result instanceof \mysqli_result) {
            throw new StorageException('SQL mirror statement failed: ' . $this->mysqli->error);
        }

        try {
            while (is_array($row = $result->fetch_row())) {
                yield array_values($row);
            }
        } finally {
            $result->free();
        }
    }

    public function statement(string $sql): SqlMirrorStatement
    {
        try {
            $statement = $this->mysqli->prepare($sql);
        } catch (\mysqli_sql_exception $exception) {
            throw new StorageException('SQL mirror statement failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (false === $statement) {
            throw new StorageException('SQL mirror statement failed: ' . $this->mysqli->error);
        }

        return new MysqliSqlMirrorStatement($statement);
    }

    public function begin(): void
    {
        try {
            $began = $this->mysqli->begin_transaction();
        } catch (\mysqli_sql_exception $exception) {
            throw new StorageException('SQL mirror transaction failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (! $began || $this->in_transaction) {
            throw new StorageException('SQL mirror transaction failed: ' . ( $this->in_transaction ? 'There is already an active transaction' : $this->mysqli->error ));
        }

        $this->in_transaction = true;
    }

    public function commit(): void
    {
        $this->in_transaction = false;
        try {
            $committed = $this->mysqli->commit();
        } catch (\mysqli_sql_exception $exception) {
            throw new StorageException('SQL mirror transaction failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (! $committed) {
            throw new StorageException('SQL mirror transaction failed: ' . $this->mysqli->error);
        }
    }

    public function rollback(): void
    {
        $this->in_transaction = false;
        try {
            $rolled_back = $this->mysqli->rollback();
        } catch (\mysqli_sql_exception $exception) {
            throw new StorageException('SQL mirror transaction failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (! $rolled_back) {
            throw new StorageException('SQL mirror transaction failed: ' . $this->mysqli->error);
        }
    }

    public function in_transaction(): bool
    {
        return $this->in_transaction;
    }
}
