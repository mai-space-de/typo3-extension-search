<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Facet;

/**
 * A facet definition that can be rendered in the frontend.
 */
interface FacetInterface
{
    public function getName(): string;

    public function getLabel(): string;

    /**
     * @return FacetValue[]
     */
    public function getValues(): array;

    public function isActive(): bool;

    /**
     * @return string[] Active facet values
     */
    public function getActiveValues(): array;
}
