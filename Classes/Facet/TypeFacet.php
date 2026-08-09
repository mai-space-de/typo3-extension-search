<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Facet;

/**
 * Facet for filtering by content type (news, page, events, etc.).
 */
class TypeFacet implements FacetInterface
{
    /**
     * @param FacetValue[] $values
     * @param string[] $activeValues
     */
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly array $values = [],
        private readonly array $activeValues = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValues(): array
    {
        return $this->values;
    }

    public function isActive(): bool
    {
        return $this->activeValues !== [];
    }

    public function getActiveValues(): array
    {
        return $this->activeValues;
    }

    public function getTotal(): int
    {
        return array_sum(array_map(static fn(FacetValue $value): int => $value->count, $this->values));
    }
}
