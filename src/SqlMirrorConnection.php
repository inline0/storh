<?php

declare(strict_types=1);

namespace Storh;

interface SqlMirrorConnection
{
    public function driver(): string;

    public function execute(string $sql): void;

    /**
     * @return \Generator<int, list<mixed>>
     */
    public function rows(string $sql): \Generator;

    public function statement(string $sql): SqlMirrorStatement;

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function in_transaction(): bool;
}
