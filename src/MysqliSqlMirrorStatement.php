<?php

declare(strict_types=1);

namespace Storh;

/**
 * @codeCoverageIgnore Requires a live MySQL server; exercised by the SQL Mirror MySQL CI job.
 */
final class MysqliSqlMirrorStatement implements SqlMirrorStatement
{
    public function __construct(private readonly \mysqli_stmt $statement)
    {
    }

    /**
     * @param list<int|float|string|null> $parameters
     */
    public function execute(array $parameters): void
    {
        try {
            $executed = $this->statement->execute($parameters);
        } catch (\mysqli_sql_exception $exception) {
            throw new StorageException('SQL mirror statement failed: ' . $exception->getMessage(), 0, $exception);
        }

        if (! $executed) {
            throw new StorageException('SQL mirror statement failed: ' . $this->statement->error);
        }
    }
}
