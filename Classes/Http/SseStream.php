<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\SelfEmittableStreamInterface;
use TYPO3\CMS\Core\Http\Stream;

/**
 * A PSR-7 stream whose emit() writes Server-Sent Events to the output buffer
 * as tokens are produced, instead of building the whole response body in
 * memory first. TYPO3\CMS\Core\Http\AbstractApplication::sendResponse() checks
 * for SelfEmittableStreamInterface and calls emit() instead of echoing
 * __toString() — the same fast-path TYPO3 core's own
 * FalDumpFileContentsDecoratorStream uses for large file downloads
 * (SPEC-v2.md Phase 7 addendum).
 *
 * __toString()/read()/etc. (via StreamDecoratorTrait + createStream()) are
 * never exercised on the real request path — sendResponse() always prefers
 * emit() when available — they exist only to satisfy StreamInterface for
 * callers (tests, other middlewares) that don't check for
 * SelfEmittableStreamInterface.
 *
 * Items yielded by $events may be either:
 *  - string — emitted as `event: token` with `{"token": "..."}`
 *  - array{event: string, data: array<string, mixed>} — emitted as-is (e.g. status)
 */
class SseStream implements StreamInterface, SelfEmittableStreamInterface, LoggerAwareInterface
{
    use StreamDecoratorTrait;
    use LoggerAwareTrait;

    /**
     * Bytes of leading SSE comment used to fill proxy/FastCGI buffers so the
     * first real event is flushed to the client immediately.
     */
    private const int PROXY_PADDING_BYTES = 8192;

    /**
     * @param iterable<int, string|array{event: string, data: array<string, mixed>}> $events
     * @param \Closure(): array<string, mixed> $finalize Called once all events are consumed, to build
     *                                                    the payload of the closing "done" event
     */
    public function __construct(
        private readonly iterable $events,
        private readonly \Closure $finalize,
    ) {}

    public function emit(): void
    {
        $this->prepareOutput();
        $this->writePadding();

        try {
            foreach ($this->events as $item) {
                if (is_array($item)) {
                    $this->writeEvent($item['event'], $item['data']);
                } else {
                    $this->writeEvent('token', ['token' => (string) $item]);
                }
            }

            $this->writeEvent('done', ($this->finalize)());
        } catch (\Throwable $e) {
            $this->logger?->error('mai_search answer stream failed mid-flight', ['exception' => $e->getMessage()]);
            $this->writeEvent('error', ['message' => 'The answer could not be generated.']);
        }
    }

    protected function createStream(): StreamInterface
    {
        return new Stream('php://temp', 'rw');
    }

    private function prepareOutput(): void
    {
        // AbstractApplication already ends output buffers before emit().
        // Do not call ob_end_* here — that would close PHPUnit's test buffer
        // (and any intentional outer buffer) and drop captured SSE frames.
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
    }

    private function writePadding(): void
    {
        // SSE comment lines are ignored by EventSource/fetch parsers but fill
        // intermediary buffers (Apache/mod_proxy_fcgi, reverse proxies).
        echo ':' . str_repeat(' ', self::PROXY_PADDING_BYTES) . "\n\n";
        $this->flushOutput();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeEvent(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        $this->flushOutput();
    }

    private function flushOutput(): void
    {
        // Only flush the SAPI — do not ob_flush(), which empties nested
        // capturing buffers (PHPUnit) without reaching the client anyway.
        flush();
    }
}