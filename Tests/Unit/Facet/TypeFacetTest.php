<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Facet;

use Maispace\MaiSearch\Facet\FacetValue;
use Maispace\MaiSearch\Facet\TypeFacet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TypeFacetTest extends TestCase
{
    #[Test]
    public function exposesNameAndLabel(): void
    {
        $facet = new TypeFacet('type', 'Type');

        self::assertSame('type', $facet->getName());
        self::assertSame('Type', $facet->getLabel());
    }

    #[Test]
    public function isActiveReflectsWhetherActiveValuesArePresent(): void
    {
        self::assertFalse((new TypeFacet('type', 'Type'))->isActive());
        self::assertTrue((new TypeFacet('type', 'Type', [], ['news']))->isActive());
    }

    #[Test]
    public function getActiveValuesReturnsGivenValues(): void
    {
        $facet = new TypeFacet('type', 'Type', [], ['news', 'events']);

        self::assertSame(['news', 'events'], $facet->getActiveValues());
    }

    #[Test]
    public function getTotalSumsAllValueCounts(): void
    {
        $facet = new TypeFacet('type', 'Type', [
            new FacetValue('news', 'News', 3),
            new FacetValue('page', 'Page', 5),
            new FacetValue('events', 'Events', 2),
        ]);

        self::assertSame(10, $facet->getTotal());
    }

    #[Test]
    public function getTotalIsZeroForNoValues(): void
    {
        self::assertSame(0, (new TypeFacet('type', 'Type'))->getTotal());
    }
}
