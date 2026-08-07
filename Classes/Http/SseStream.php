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
 */
class SseStream implements StreamInterface, SelfEmittableStreamInterface, LoggerAwareInterface
{
    use StreamDecoratorTrait;
    use LoggerAwareTrait;

    /**
     * @param iterable<string> $tokens Token chunks to emit as they're produced
     * @param \Closure(): array<string, mixed> $finalize Called once all tokens are consumed, to build
     *                                                    the payload of the closing "done" event (e.g.
     *                                                    accumulated answer, sources, updated history)
     */
    public function __construct(
        private readonly iterable $tokens,
        private readonly \Closure $finalize,
    ) {}

    public function emit(): void
    {
        try {
            foreach ($this->tokens as $token) {
                $this->writeEvent('token', ['token' => $token]);
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

    private function writeEvent(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();
    }
}
