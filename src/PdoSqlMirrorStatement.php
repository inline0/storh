<?php

declare(strict_types=1);

namespace Storh;

final class PdoSqlMirrorStatement implements SqlMirrorStatement
{
    public function __construct(private readonly \PDOStatement $statement)
    {
    }

    /**
     * @param list<int|float|string|null> $parameters
     */
    public function execute(array $parameters): void
    {
        try {
            $this->statement->execute($parameters);
        } catch (\PDOException $exception) {
            throw new StorageException('SQL mirror statement failed: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
