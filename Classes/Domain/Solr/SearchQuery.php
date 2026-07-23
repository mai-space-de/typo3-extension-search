<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Solr;

/**
 * Search request parameters for Solr select queries.
 */
final class SearchQuery
{
    private string $query = '*:*';
    private int $start = 0;
    private int $rows = 10;

    /**
     * @var list<array{query: string, tag: ?string}>
     */
    private array $filterQueries = [];

    /**
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * @var array<string, array{field: string, excludes: list<string>}> facet key => config
     */
    private array $facetFields = [];

    public function setQuery(string $query): self
    {
        $this->query = $query;

        return $this;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function setStart(int $start): self
    {
        $this->start = max(0, $start);

        return $this;
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function setRows(int $rows): self
    {
        $this->rows = max(0, $rows);

        return $this;
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    public function addFilterQuery(string $filterQuery, ?string $tag = null): self
    {
        $this->filterQueries[] = [
            'query' => $filterQuery,
            'tag' => $tag,
        ];

        return $this;
    }

    /**
     * @return list<array{query: string, tag: ?string}>
     */
    public function getFilterQueries(): array
    {
        return $this->filterQueries;
    }

    public function addParam(string $name, mixed $value): self
    {
        $this->params[$name] = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param list<string> $excludes
     */
    public function addFacetField(string $key, string $field, array $excludes = []): self
    {
        $this->facetFields[$key] = [
            'field' => $field,
            'excludes' => $excludes,
        ];

        return $this;
    }

    /**
     * @return array<string, array{field: string, excludes: list<string>}>
     */
    public function getFacetFields(): array
    {
        return $this->facetFields;
    }
}
