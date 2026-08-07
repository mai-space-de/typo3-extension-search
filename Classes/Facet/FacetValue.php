<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Facet;

/**
 * Represents a single facet value with its count.
 */
class FacetValue
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly int $count,
        public readonly bool $active = false,
    ) {}
}
