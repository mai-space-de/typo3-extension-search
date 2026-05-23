<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

class IndexingContext
{
    /**
     * @param int<1, max> $batchSize
     */
    public function __construct(
        public readonly string $core,
        public readonly int $batchSize = 100,
        public readonly int $offset = 0,
    ) {}
}
