<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Model;

use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BackendCapabilitiesTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $capabilities = new BackendCapabilities(
            supportsRealtimeIndexing: true,
            supportsFacets: true,
            supportsVectorSearch: false,
            hasComparableRelevanceScore: true,
        );

        self::assertTrue($capabilities->supportsRealtimeIndexing);
        self::assertTrue($capabilities->supportsFacets);
        self::assertFalse($capabilities->supportsVectorSearch);
        self::assertTrue($capabilities->hasComparableRelevanceScore);
    }

    #[Test]
    public function reportsDegradedCapabilitiesForIndexedSearch(): void
    {
        $capabilities = new BackendCapabilities(
            supportsRealtimeIndexing: false,
            supportsFacets: false,
            supportsVectorSearch: false,
            hasComparableRelevanceScore: false,
        );

        self::assertFalse($capabilities->supportsRealtimeIndexing);
        self::assertFalse($capabilities->supportsFacets);
        self::assertFalse($capabilities->supportsVectorSearch);
        self::assertFalse($capabilities->hasComparableRelevanceScore);
    }
}
