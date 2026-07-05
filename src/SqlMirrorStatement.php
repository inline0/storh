<?php

declare(strict_types=1);

namespace Storh;

interface SqlMirrorStatement
{
    /**
     * @param list<int|float|string|null> $parameters
     */
    public function execute(array $parameters): void;
}
