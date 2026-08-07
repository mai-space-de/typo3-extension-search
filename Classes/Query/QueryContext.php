<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Query;

/**
 * Context object for query modification.
 */
class QueryContext
{
    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $facets
     */
    public function __construct(
        public string $query = '',
        public int $limit = 20,
        public int $offset = 0,
        public ?int $languageUid = null,
        public array $filters = [],
        public array $facets = [],
        public bool $ragEnabled = false,
    ) {}

    public function withFilter(string $field, mixed $value): self
    {
        $clone = clone $this;
        $clone->filters[$field] = $value;

        return $clone;
    }

    public function withFacet(string $name, array $values): self
    {
        $clone = clone $this;
        $clone->facets[$name] = $values;

        return $clone;
    }

    public function withPagination(int $limit, int $offset): self
    {
        $clone = clone $this;
        $clone->limit = $limit;
        $clone->offset = $offset;

        return $clone;
    }
}
