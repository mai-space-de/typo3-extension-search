<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Dto;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SearchResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $date = new \DateTime('2026-05-23');

        $result = new SearchResult(
            type: 'news',
            title: 'Test Title',
            snippet: 'Test snippet content...',
            url: '/news/test-title',
            icon: 'news-icon',
            date: $date,
            score: 0.95,
        );

        self::assertSame('news', $result->type);
        self::assertSame('Test Title', $result->title);
        self::assertSame('Test snippet content...', $result->snippet);
        self::assertSame('/news/test-title', $result->url);
        self::assertSame('news-icon', $result->icon);
        self::assertSame($date, $result->date);
        self::assertSame(0.95, $result->score);
    }

    #[Test]
    public function constructorAllowsNullDate(): void
    {
        $result = new SearchResult(
            type: 'page',
            title: 'No Date',
            snippet: '',
            url: '/no-date',
            icon: 'page-icon',
            date: null,
            score: 0.5,
        );

        self::assertNull($result->date);
    }

    #[Test]
    public function constructorDefaultsRootlineToNull(): void
    {
        $result = new SearchResult(
            type: 'page',
            title: 'Rootless',
            snippet: '',
            url: '/rootless',
            icon: 'page-icon',
            date: null,
            score: 0.5,
        );

        self::assertNull($result->rootline);
    }

    #[Test]
    public function constructorAcceptsRootlineArray(): void
    {
        $rootline = ['Home', 'News', 'Category A'];

        $result = new SearchResult(
            type: 'page',
            title: 'Deep Page',
            snippet: '',
            url: '/deep',
            icon: 'page-icon',
            date: null,
            score: 0.5,
            rootline: $rootline,
        );

        self::assertSame($rootline, $result->rootline);
    }
}
