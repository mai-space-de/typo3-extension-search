<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Http;

use Maispace\MaiSearch\Http\SseStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\SelfEmittableStreamInterface;

final class SseStreamTest extends TestCase
{
    /**
     * @param list<string|array{event: string, data: array<string, mixed>}> $events
     */
    private function events(array $events): \Generator
    {
        yield from $events;
    }

    #[Test]
    public function implementsSelfEmittableStreamInterface(): void
    {
        $stream = new SseStream($this->events([]), static fn(): array => []);

        self::assertInstanceOf(SelfEmittableStreamInterface::class, $stream);
        self::assertInstanceOf(StreamInterface::class, $stream);
    }

    #[Test]
    public function emitWritesOneTokenEventPerToken(): void
    {
        $stream = new SseStream($this->events(['Hello', ' world']), static fn(): array => ['answer' => 'Hello world']);

        ob_start();
        $stream->emit();
        $output = ob_get_clean();

        self::assertSame(2, substr_count((string) $output, 'event: token'));
        self::assertStringContainsString('data: {"token":"Hello"}', (string) $output);
        self::assertStringContainsString('data: {"token":" world"}', (string) $output);
    }

    #[Test]
    public function emitWritesStructuredStatusEvents(): void
    {
        $stream = new SseStream(
            $this->events([
                ['event' => 'status', 'data' => ['phase' => 'searching']],
                'Hi',
            ]),
            static fn(): array => ['answer' => 'Hi'],
        );

        ob_start();
        $stream->emit();
        $output = ob_get_clean();

        self::assertStringContainsString('event: status', (string) $output);
        self::assertStringContainsString('"phase":"searching"', (string) $output);
        self::assertStringContainsString('event: token', (string) $output);
    }

    #[Test]
    public function emitWritesProxyPaddingBeforeEvents(): void
    {
        $stream = new SseStream($this->events(['x']), static fn(): array => []);

        ob_start();
        $stream->emit();
        $output = (string) ob_get_clean();

        self::assertStringStartsWith(':', $output);
        self::assertGreaterThan(8000, strpos($output, 'event: token') ?: 0);
    }

    #[Test]
    public function emitWritesADoneEventWithTheFinalizePayloadAfterAllTokens(): void
    {
        $stream = new SseStream(
            $this->events(['a', 'b']),
            static fn(): array => ['answer' => 'ab', 'sources' => [], 'history' => []],
        );

        ob_start();
        $stream->emit();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('event: done', $output);
        self::assertStringContainsString('"answer":"ab"', $output);

        $donePosition = strpos($output, 'event: done');
        $lastTokenPosition = strrpos($output, 'event: token');
        self::assertGreaterThan($lastTokenPosition, $donePosition);
    }

    #[Test]
    public function emitWritesAnErrorEventWhenTokenGenerationThrows(): void
    {
        $failingTokens = (function (): \Generator {
            yield 'partial';
            throw new \RuntimeException('provider unreachable');
        })();

        $stream = new SseStream($failingTokens, static fn(): array => ['answer' => 'unused']);

        ob_start();
        $stream->emit();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('event: token', $output);
        self::assertStringContainsString('event: error', $output);
        self::assertStringNotContainsString('event: done', $output);
    }

    #[Test]
    public function emitDoesNotThrowEvenWhenTokenGenerationFails(): void
    {
        $failingTokens = (function (): \Generator {
            throw new \RuntimeException('boom');
            yield '';
        })();

        $stream = new SseStream($failingTokens, static fn(): array => []);

        ob_start();
        $stream->emit();
        ob_get_clean();

        self::assertTrue(true);
    }
}
