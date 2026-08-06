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
}
