<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Model;

use Maispace\MaiSearch\Domain\Model\SearchRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SearchRequestTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $request = new SearchRequest(
            query: 'gymnastics',
            limit: 10,
            offset: 20,
            language: null,
            type: 'news',
            ragEnabled: true,
        );

        self::assertSame('gymnastics', $request->query);
        self::assertSame(10, $request->limit);
        self::assertSame(20, $request->offset);
        self::assertNull($request->language);
        self::assertSame('news', $request->type);
        self::assertTrue($request->ragEnabled);
    }

    #[Test]
    public function usesDefaultValues(): void
    {
        $request = new SearchRequest(query: 'gymnastics');

        self::assertSame(20, $request->limit);
        self::assertSame(0, $request->offset);
        self::assertNull($request->language);
        self::assertNull($request->type);
        self::assertFalse($request->ragEnabled);
    }
}
