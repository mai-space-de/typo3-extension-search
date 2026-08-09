<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Facet;

use Maispace\MaiSearch\Facet\FacetRegistry;
use Maispace\MaiSearch\Facet\TypeFacet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FacetRegistryTest extends TestCase
{
    #[Test]
    public function addFacetMakesItRetrievableByName(): void
    {
        $registry = new FacetRegistry();
        $typeFacet = new TypeFacet('type', 'Type');

        $registry->addFacet($typeFacet);

        self::assertSame($typeFacet, $registry->getFacet('type'));
    }

    #[Test]
    public function getFacetReturnsNullForUnknownName(): void
    {
        $registry = new FacetRegistry();

        self::assertNull($registry->getFacet('unknown'));
    }

    #[Test]
    public function hasFacetReflectsRegistration(): void
    {
        $registry = new FacetRegistry();

        self::assertFalse($registry->hasFacet('type'));

        $registry->addFacet(new TypeFacet('type', 'Type'));

        self::assertTrue($registry->hasFacet('type'));
    }

    #[Test]
    public function getAllFacetsReturnsEveryRegisteredFacet(): void
    {
        $registry = new FacetRegistry();
        $registry->addFacet(new TypeFacet('type', 'Type'));
        $registry->addFacet(new TypeFacet('category', 'Category'));

        self::assertCount(2, $registry->getAllFacets());
    }

    #[Test]
    public function getActiveFacetsOnlyReturnsFacetsWithActiveValues(): void
    {
        $registry = new FacetRegistry();
        $registry->addFacet(new TypeFacet('type', 'Type', [], []));
        $registry->addFacet(new TypeFacet('category', 'Category', [], ['news']));

        $active = $registry->getActiveFacets();

        self::assertCount(1, $active);
        self::assertSame('category', array_values($active)[0]->getName());
    }

    #[Test]
    public function addFacetWithSameNameOverwritesThePreviousOne(): void
    {
        $registry = new FacetRegistry();
        $first = new TypeFacet('type', 'Type');
        $second = new TypeFacet('type', 'Art');

        $registry->addFacet($first);
        $registry->addFacet($second);

        self::assertSame($second, $registry->getFacet('type'));
        self::assertCount(1, $registry->getAllFacets());
    }
}
