<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Backend\Meilisearch;

use Maispace\MaiSearch\Backend\Meilisearch\IndexNameResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexNameResolverTest extends TestCase
{
    #[Test]
    public function forLanguageAppendsLanguageCodeSuffix(): void
    {
        $resolver = new IndexNameResolver();

        self::assertSame('mai_search_de', $resolver->forLanguage('de'));
        self::assertSame('mai_search_en', $resolver->forLanguage('en'));
    }

    #[Test]
    public function forLanguageReturnsBareNameWhenNoLanguageGiven(): void
    {
        $resolver = new IndexNameResolver();

        self::assertSame('mai_search', $resolver->forLanguage(null));
        self::assertSame('mai_search', $resolver->forLanguage(''));
    }

    #[Test]
    public function forChunksAppendsLanguageCodeSuffix(): void
    {
        $resolver = new IndexNameResolver();

        self::assertSame('mai_search_chunks_de', $resolver->forChunks('de'));
        self::assertSame('mai_search_chunks_en', $resolver->forChunks('en'));
    }

    #[Test]
    public function forChunksReturnsBareNameWhenNoLanguageGiven(): void
    {
        $resolver = new IndexNameResolver();

        self::assertSame('mai_search_chunks', $resolver->forChunks(null));
        self::assertSame('mai_search_chunks', $resolver->forChunks(''));
    }

    #[Test]
    public function forChunksIsDistinctFromForLanguage(): void
    {
        $resolver = new IndexNameResolver();

        self::assertNotSame($resolver->forLanguage('de'), $resolver->forChunks('de'));
    }
}
