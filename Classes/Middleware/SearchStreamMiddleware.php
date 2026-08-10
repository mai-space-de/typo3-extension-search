<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Middleware;

use Maispace\MaiBase\Middleware\Api\AbstractApiMiddleware;
use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Model\ConversationHistory;
use Maispace\MaiSearch\Domain\Service\RagContextResolver;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use Maispace\MaiSearch\Domain\Service\StreamingChatProviderInterface;
use Maispace\MaiSearch\Http\RagApiSupport;
use Maispace\MaiSearch\Http\SseStream;
use Maispace\MaiSearch\Service\ProviderAvailabilityService;
use Maispace\MaiSearch\Service\ProviderRegistry;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Token-by-token RAG answer synthesis over Server-Sent Events (SPEC-v2.md
 * Phase 7 addendum). Progressive enhancement only — proxy-safe sync fallback
 * is SearchAnswerMiddleware (`POST /api/search/answer`).
 *
 * Extbase ActionController buffers the response body, which defeats streaming;
 * see Classes/Http/SseStream.php (SelfEmittableStreamInterface).
 */
class SearchStreamMiddleware extends AbstractApiMiddleware
{
    private const API_PATH = '/api/search/stream';

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        private readonly SearchService $searchService,
        private readonly RagContextResolver $ragContextResolver,
        private readonly SearchSynthesisService $synthesisService,
        private readonly ProviderRegistry $providerRegistry,
        private readonly RagConfiguration $ragConfiguration,
        private readonly ProviderAvailabilityService $providerAvailability,
        private readonly RagApiSupport $ragApiSupport,
    ) {
        parent::__construct($responseFactory, $streamFactory);
    }

    public function shouldHandle(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getPath() === self::API_PATH && $this->isMethod($request, 'POST');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->ragConfiguration->isStreamingEnabled()) {
            return $this->errorResponse('Streaming is disabled.', 404);
        }

        if (!$this->providerAvailability->isChatProviderAvailable()) {
            return $this->errorResponse('Chat provider is unavailable.', 503);
        }

        $payload = $this->decodeJsonBody($request);
        $query = trim((string) ($payload['query'] ?? ''));

        if ($query === '') {
            return $this->errorResponse('Missing query.', 400);
        }

        $type = isset($payload['type']) && $payload['type'] !== '' ? (string) $payload['type'] : null;
        $history = ConversationHistory::fromJson((string) ($payload['history'] ?? ''));
        $language = $this->ragApiSupport->resolveLanguage(
            $request,
            isset($payload['language']) ? (string) $payload['language'] : null,
        );
        $clientContext = $this->ragApiSupport->parseContext($payload['context'] ?? null);

        $chatProvider = $this->providerRegistry->getActiveChatProvider();
        if (!$chatProvider instanceof StreamingChatProviderInterface) {
            return $this->errorResponse('The active chat provider does not support streaming.', 501);
        }

        return $this->streamResponse(
            $chatProvider,
            $query,
            $language,
            $type,
            $history,
            $clientContext,
        );
    }

    /**
     * @param SearchResult[] $clientContext
     */
    private function streamResponse(
        StreamingChatProviderInterface $chatProvider,
        string $query,
        ?SiteLanguage $language,
        ?string $type,
        ConversationHistory $history,
        array $clientContext,
    ): ResponseInterface {
        $sources = [];
        $answer = '';

        $events = (function () use (
            $chatProvider,
            $query,
            $language,
            $type,
            $history,
            $clientContext,
            &$sources,
            &$answer,
        ): \Generator {
            yield ['event' => 'status', 'data' => ['phase' => 'searching']];

            $context = $this->resolveContext($query, $language, $type, $clientContext);
            if ($context === []) {
                throw new \RuntimeException('No results to answer from.');
            }

            $sources = $this->ragApiSupport->buildSources($context);

            // SPEC-v3-caching-chunking.md Phase C: a cache hit skips the live
            // chat-completion call entirely and is emitted as a fast burst of
            // `token` events instead of one big blob — the frontend needs no
            // special-casing, it just sees the same event shape delivered faster.
            $cachedAnswer = $this->synthesisService->getCachedAnswer($query, $context, $history);
            if ($cachedAnswer !== null) {
                $answer = $cachedAnswer;

                yield ['event' => 'status', 'data' => ['phase' => 'cached']];

                foreach (self::splitIntoBurstChunks($cachedAnswer) as $chunk) {
                    yield $chunk;
                }

                return;
            }

            $chatRequest = $this->synthesisService->buildChatRequest($query, $context, $history);

            yield ['event' => 'status', 'data' => ['phase' => 'generating']];

            foreach ($chatProvider->stream($chatRequest) as $token) {
                $answer .= $token;

                yield $token;
            }

            $this->synthesisService->cacheAnswer($query, $context, $history, $answer);
        })();

        $finalize = function () use (&$answer, &$sources, $history, $query): array {
            $updatedHistory = $history
                ->withUserMessage($query)
                ->withAssistantMessage($answer)
                ->truncated($this->ragConfiguration->getHistoryMaxTurns());

            return [
                'answer' => $answer,
                'sources' => $sources,
                'history' => $updatedHistory->toArray(),
            ];
        };

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withHeader('Connection', 'keep-alive')
            ->withBody(new SseStream($events, $finalize));
    }

    /**
     * Splits a cached answer into word-sized pieces so a cache hit is still delivered
     * as a sequence of `token` SSE events (SPEC-v3-caching-chunking.md Phase C) —
     * same wire shape as a live stream, just sent back-to-back with no generation
     * delay between frames, which reads as a fast "typed" burst client-side.
     *
     * @return list<string>
     */
    private static function splitIntoBurstChunks(string $answer): array
    {
        $chunks = preg_split('/(?<=\s)/', $answer, -1, PREG_SPLIT_NO_EMPTY);

        return $chunks !== false ? $chunks : [$answer];
    }

    /**
     * @param SearchResult[] $clientContext
     * @return SearchResult[]
     */
    private function resolveContext(
        string $query,
        ?SiteLanguage $language,
        ?string $type,
        array $clientContext,
    ): array {
        if ($clientContext !== [] && !$this->ragConfiguration->isRerankEnabled()) {
            return $clientContext;
        }

        if ($this->ragConfiguration->isRerankEnabled()) {
            return $this->ragContextResolver->resolve(
                $query,
                $language,
                $type,
                $this->ragConfiguration->getTopN(),
                $this->ragConfiguration->getRerankWidth(),
            );
        }

        return $this->searchService->search(
            $query,
            $this->ragConfiguration->getTopN(),
            0,
            $language,
            true,
            $type,
        )->results;
    }
}
