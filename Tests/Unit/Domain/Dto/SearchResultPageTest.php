<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Dto;

use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Facet\FacetValue;
use Maispace\MaiSearch\Facet\TypeFacet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SearchResultPageTest extends TestCase
{
    #[Test]
    public function getTotalPagesCeilsResultCountOverPerPage(): void
    {
        $page = new SearchResultPage([], 45, [], 1, 20);

        self::assertSame(3, $page->getTotalPages());
    }

    #[Test]
    public function getTotalPagesReturnsZeroForNonPositivePerPage(): void
    {
        $page = new SearchResultPage([], 10, [], 1, 0);

        self::assertSame(0, $page->getTotalPages());
    }

    #[Test]
    public function getFacetReturnsMatchingFacetByName(): void
    {
        $typeFacet = new TypeFacet('type', 'Type', [new FacetValue('news', 'News', 3)]);
        $page = new SearchResultPage([], 3, [$typeFacet]);

        self::assertSame($typeFacet, $page->getFacet('type'));
    }

    #[Test]
    public function getFacetReturnsNullForUnknownName(): void
    {
        $page = new SearchResultPage([], 0, []);

        self::assertNull($page->getFacet('type'));
    }
}
