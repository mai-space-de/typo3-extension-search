<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Facet;

/**
 * Registry for managing available facets.
 */
class FacetRegistry
{
    /**
     * @var FacetInterface[]
     */
    private array $facets = [];

    public function addFacet(FacetInterface $facet): void
    {
        $this->facets[$facet->getName()] = $facet;
    }

    public function getFacet(string $name): ?FacetInterface
    {
        return $this->facets[$name] ?? null;
    }

    /**
     * @return FacetInterface[]
     */
    public function getAllFacets(): array
    {
        return array_values($this->facets);
    }

    /**
     * @return FacetInterface[]
     */
    public function getActiveFacets(): array
    {
        return array_filter($this->facets, static fn(FacetInterface $facet): bool => $facet->isActive());
    }

    public function hasFacet(string $name): bool
    {
        return isset($this->facets[$name]);
    }
}
