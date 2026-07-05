<?php

declare(strict_types=1);

namespace Storh\Tests\Support;

use Storh\SqlMirrorConnection;
use Storh\SqlMirrorStatement;
use Storh\StorageException;

final class FlakySqlMirrorConnection implements SqlMirrorConnection
{
    private int $executed = 0;

    public function __construct(
        private readonly SqlMirrorConnection $inner,
        private readonly int $fail_at
    ) {
    }

    public function driver(): string
    {
        return $this->inner->driver();
    }

    public function execute(string $sql): void
    {
        $this->inner->execute($sql);
    }

    /**
     * @return \Generator<int, list<mixed>>
     */
    public function rows(string $sql): \Generator
    {
        yield from $this->inner->rows($sql);
    }

    public function statement(string $sql): SqlMirrorStatement
    {
        $inner = $this->inner->statement($sql);
        $owner = $this;

        return new class ($inner, $owner) implements SqlMirrorStatement {
            public function __construct(
                private readonly SqlMirrorStatement $inner,
                private readonly FlakySqlMirrorConnection $owner
            ) {
            }

            /**
             * @param list<int|float|string|null> $parameters
             */
            public function execute(array $parameters): void
            {
                $this->owner->count_execute();
                $this->inner->execute($parameters);
            }
        };
    }

    public function count_execute(): void
    {
        $this->executed++;
        if ($this->executed === $this->fail_at) {
            throw new StorageException('Injected SQL mirror failure.');
        }
    }

    public function begin(): void
    {
        $this->inner->begin();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }

    public function in_transaction(): bool
    {
        return $this->inner->in_transaction();
    }
}
