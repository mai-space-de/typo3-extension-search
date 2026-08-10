<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Http;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Http\RagApiSupport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class RagApiSupportTest extends TestCase
{
    private RagApiSupport $subject;

    protected function setUp(): void
    {
        $this->subject = new RagApiSupport();
    }

    #[Test]
    public function parseContextSkipsInvalidEntriesAndBuildsSearchResults(): void
    {
        $results = $this->subject->parseContext([
            ['url' => '/a', 'title' => 'A', 'snippet' => 'sa', 'type' => 'page', 'score' => 1.5],
            ['title' => 'missing-url'],
            'not-an-array',
            ['url' => '/b', 'title' => 'B'],
        ]);

        self::assertCount(2, $results);
        self::assertSame('/a', $results[0]->url);
        self::assertSame('A', $results[0]->title);
        self::assertSame(1.5, $results[0]->score);
        self::assertSame('/b', $results[1]->url);
    }

    #[Test]
    public function serializeContextRespectsLimit(): void
    {
        $results = [
            new SearchResult('page', 'A', 'a', '/a', '', null, 1.0),
            new SearchResult('page', 'B', 'b', '/b', '', null, 2.0),
        ];

        $payload = $this->subject->serializeContext($results, 1);

        self::assertCount(1, $payload);
        self::assertSame('/a', $payload[0]['url']);
        self::assertSame('A', $payload[0]['title']);
    }

    #[Test]
    public function resolveLanguageFallsBackToRequestAttribute(): void
    {
        $language = $this->createMock(\TYPO3\CMS\Core\Site\Entity\SiteLanguage::class);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => $name === 'language' ? $language : null,
        );

        self::assertSame($language, $this->subject->resolveLanguage($request, null));
    }

    #[Test]
    public function buildSourcesMapsResultFields(): void
    {
        $sources = $this->subject->buildSources([
            new SearchResult('news', 'Title', 'snip', '/u', 'icon', null, 3.2),
        ]);

        self::assertSame([
            ['title' => 'Title', 'url' => '/u', 'type' => 'news', 'score' => 3.2],
        ], $sources);
    }
}
