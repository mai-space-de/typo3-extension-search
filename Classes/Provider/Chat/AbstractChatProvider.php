<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Provider\Chat;

use Maispace\MaiSearch\Domain\Model\ChatRequest;
use Maispace\MaiSearch\Domain\Model\ChatResponse;
use Maispace\MaiSearch\Domain\Service\StreamingChatProviderInterface;
use Maispace\MaiSearch\Support\ExtractsResponsePath;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Shared line-based stream parsing for chat providers. Concrete providers only
 * need to build the request and pick a wire format (SSE for OpenAI-compatible
 * APIs, NDJSON for Ollama) — complete() is derived from stream() for free.
 */
abstract class AbstractChatProvider implements StreamingChatProviderInterface, LoggerAwareInterface
{
    use ExtractsResponsePath;
    use LoggerAwareTrait;

    public function __construct(protected readonly RequestFactory $requestFactory) {}

    public function complete(ChatRequest $request): ChatResponse
    {
        $answer = '';
        foreach ($this->stream($request) as $token) {
            $answer .= $token;
        }

        return new ChatResponse($answer);
    }

    /**
     * The request options pass `stream: true` to avoid buffering the body, but the
     * status line and headers are already available by the time request() returns —
     * so a failed call (auth error, rate limit, ...) can be caught here before its
     * error body is fed to the SSE/NDJSON parser, which would otherwise just yield no
     * tokens and produce a silently empty answer.
     *
     * @throws \RuntimeException when the response status is not successful; logged
     *   before throwing.
     */
    protected function assertSuccessful(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 300) {
            $this->logger?->error(
                '{provider} chat completions API returned HTTP {status}',
                ['provider' => $this->getIdentifier(), 'status' => $statusCode],
            );

            throw new \RuntimeException(sprintf('%s chat completions API error (HTTP %d)', $this->getIdentifier(), $statusCode));
        }
    }

    /**
     * Parses an OpenAI-style `data: {...}` SSE stream, yielding one token per chunk.
     *
     * @return \Generator<int, string>
     */
    protected function streamSse(ResponseInterface $response, string $dataPath = 'choices.0.delta.content'): \Generator
    {
        yield from $this->streamLines($response, function (string $line) use ($dataPath): ?string {
            if (!str_starts_with($line, 'data:')) {
                return null;
            }

            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                return null;
            }

            $token = $this->extractByPath(json_decode($payload, true), $dataPath);

            return is_string($token) ? $token : null;
        });
    }

    /**
     * Parses a newline-delimited-JSON stream (one JSON object per line), as used by Ollama.
     *
     * @return \Generator<int, string>
     */
    protected function streamNdjson(ResponseInterface $response, string $dataPath = 'message.content'): \Generator
    {
        yield from $this->streamLines($response, function (string $line) use ($dataPath): ?string {
            if ($line === '') {
                return null;
            }

            $token = $this->extractByPath(json_decode($line, true), $dataPath);

            return is_string($token) ? $token : null;
        });
    }

    /**
     * @return \Generator<int, string>
     */
    private function streamLines(ResponseInterface $response, \Closure $lineParser): \Generator
    {
        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(1024);

            while (($newlinePosition = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePosition));
                $buffer = substr($buffer, $newlinePosition + 1);

                $token = $lineParser($line);
                if ($token !== null && $token !== '') {
                    yield $token;
                }
            }
        }
    }
}
