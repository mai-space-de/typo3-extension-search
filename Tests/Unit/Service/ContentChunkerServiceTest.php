<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Service\ContentChunkerService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentChunkerServiceTest extends TestCase
{
    private ContentChunkerService $chunker;

    protected function setUp(): void
    {
        $this->chunker = new ContentChunkerService();
    }

    #[Test]
    public function emptyContentReturnsEmptyArray(): void
    {
        self::assertSame([], $this->chunker->chunk(''));
    }

    #[Test]
    public function whitespaceOnlyContentReturnsEmptyArray(): void
    {
        self::assertSame([], $this->chunker->chunk('   '));
    }

    #[Test]
    public function htmlWithoutTextReturnsEmptyArray(): void
    {
        self::assertSame([], $this->chunker->chunk('<p></p><div><span></span></div>'));
    }

    #[Test]
    public function contentShorterThanChunkSizeReturnsSingleChunk(): void
    {
        $chunks = $this->chunker->chunk('Hello World', chunkSize: 500);

        self::assertCount(1, $chunks);
        self::assertSame('Hello World', $chunks[0]);
    }

    #[Test]
    public function htmlTagsAreStripped(): void
    {
        $chunks = $this->chunker->chunk('<p>Hello <b>World</b></p>');

        self::assertCount(1, $chunks);
        self::assertSame('Hello World', $chunks[0]);
    }

    #[Test]
    public function whitespaceIsNormalised(): void
    {
        $chunks = $this->chunker->chunk("Hello    World\n\nFoo\tBar");

        self::assertCount(1, $chunks);
        self::assertSame('Hello World Foo Bar', $chunks[0]);
    }

    #[Test]
    public function longContentBreaksIntoMultipleChunks(): void
    {
        $chunks = $this->chunker->chunk(
            str_repeat('word ', 20),
            chunkSize: 30,
            overlap: 0,
        );

        self::assertGreaterThan(1, count($chunks));
    }

    #[Test]
    public function allChunksAreNonEmpty(): void
    {
        $chunks = $this->chunker->chunk(
            str_repeat('word ', 20),
            chunkSize: 30,
            overlap: 5,
        );

        foreach ($chunks as $chunk) {
            self::assertNotEmpty($chunk);
        }
    }

    #[Test]
    public function noChunkExceedsChunkSize(): void
    {
        $chunks = $this->chunker->chunk(
            'one two three four five six seven eight nine ten',
            chunkSize: 25,
            overlap: 5,
        );

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(25, mb_strlen($chunk));
        }
    }

    #[Test]
    public function overlapLargerThanChunkSizeIsClamped(): void
    {
        $chunks = $this->chunker->chunk('some content here for testing', chunkSize: 5, overlap: 100);

        self::assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            self::assertNotEmpty($chunk);
        }
    }

    #[Test]
    public function overlapEqualToChunkSizeIsClamped(): void
    {
        $chunks = $this->chunker->chunk('content for clamping test here', chunkSize: 10, overlap: 10);

        self::assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            self::assertNotEmpty($chunk);
        }
    }

    #[Test]
    public function complexNestedHtmlIsStrippedBeforeChunking(): void
    {
        $html = '<article><header><h1>Title Here</h1></header><section><p>Body text with <strong>formatting</strong> and <a href="#">links</a>.</p></section></article>';

        $chunks = $this->chunker->chunk($html, chunkSize: 200, overlap: 0);

        self::assertCount(1, $chunks);
        self::assertStringContainsString('Title Here', $chunks[0]);
        self::assertStringContainsString('Body text with', $chunks[0]);
        self::assertStringContainsString('formatting', $chunks[0]);
        self::assertStringContainsString('links', $chunks[0]);
        self::assertStringNotContainsString('<h1>', $chunks[0]);
        self::assertStringNotContainsString('</a>', $chunks[0]);
    }

    #[Test]
    public function chunkProducesDeterministicOutput(): void
    {
        $text = str_repeat('test data chunk ', 10);

        $first = $this->chunker->chunk($text, chunkSize: 30, overlap: 5);
        $second = $this->chunker->chunk($text, chunkSize: 30, overlap: 5);

        self::assertSame($first, $second);
    }

    #[Test]
    public function chunkWithZeroOverlapProceedsLinearly(): void
    {
        $chunks = $this->chunker->chunk(
            'one two three four five six seven eight nine ten eleven twelve',
            chunkSize: 20,
            overlap: 0,
        );

        self::assertGreaterThanOrEqual(3, count($chunks));
    }

    #[Test]
    public function singleWordContentReturnsOneChunk(): void
    {
        $chunks = $this->chunker->chunk('Supercalifragilisticexpialidocious', chunkSize: 10, overlap: 3);

        self::assertGreaterThanOrEqual(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertNotEmpty($chunk);
        }
    }

    #[Test]
    public function contentWithLeadingAndTrailingWhitespaceIsTrimmed(): void
    {
        $chunks = $this->chunker->chunk('  Hello World  ');

        self::assertCount(1, $chunks);
        self::assertSame('Hello World', $chunks[0]);
    }

    #[Test]
    public function contentWithLeadingAndTrailingHtmlIsCleaned(): void
    {
        $chunks = $this->chunker->chunk('<br/><p>  Hello World  </p><br/>');

        self::assertCount(1, $chunks);
        self::assertSame('Hello World', $chunks[0]);
    }
}
