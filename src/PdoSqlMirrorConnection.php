<?php

declare(strict_types=1);

namespace Storh;

final class PdoSqlMirrorConnection implements SqlMirrorConnection
{
    private readonly string $driver;

    public function __construct(private readonly \PDO $pdo, ?string $driver = null)
    {
        $detected = $driver ?? $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (! is_string($detected) || ! in_array($detected, array( 'sqlite', 'mysql' ), true)) {
            throw new StorageException(
                'Unsupported SQL mirror driver: ' . ( is_string($detected) ? $detected : gettype($detected) )
            );
        }

        $this->driver = $detected;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function execute(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $exception) {
            throw new StorageException('SQL mirror statement failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return \Generator<int, list<mixed>>
     */
    public function rows(string $sql): \Generator
    {
        try {
            $statement = $this->pdo->query($sql);
        } catch (\PDOException $exception) {
            throw new StorageException('SQL mirror statement failed: ' . $exception->getMessage(), 0, $exception);
        }

        // @codeCoverageIgnoreStart
        if (false === $statement) {
            throw new StorageException('SQL mirror statement failed: ' . $sql);
        }
        // @codeCoverageIgnoreEnd

        while (false !== ( $row = $statement->fetch(\PDO::FETCH_NUM) )) {
            if (is_array($row)) {
                yield array_values($row);
            }
        }
    }

    public function statement(string $sql): SqlMirrorStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
        } catch (\PDOException $exception) {
            throw new StorageException('SQL mirror statement failed: ' . $exception->getMessage(), 0, $exception);
        }

        // @codeCoverageIgnoreStart
        if (false === $statement) {
            throw new StorageException('SQL mirror statement failed: ' . $sql);
        }
        // @codeCoverageIgnoreEnd

        return new PdoSqlMirrorStatement($statement);
    }

    public function begin(): void
    {
        try {
            $this->pdo->beginTransaction();
        } catch (\PDOException $exception) {
            throw new StorageException('SQL mirror transaction failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function commit(): void
    {
        try {
            $this->pdo->commit();
            // @codeCoverageIgnoreStart
        } catch (\PDOException $exception) {
            throw new StorageException('SQL mirror transaction failed: ' . $exception->getMessage(), 0, $exception);
        }
        // @codeCoverageIgnoreEnd
    }

    public function rollback(): void
    {
        try {
            $this->pdo->rollBack();
            // @codeCoverageIgnoreStart
        } catch (\PDOException $exception) {
            throw new StorageException('SQL mirror transaction failed: ' . $exception->getMessage(), 0, $exception);
        }
        // @codeCoverageIgnoreEnd
    }

    public function in_transaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
