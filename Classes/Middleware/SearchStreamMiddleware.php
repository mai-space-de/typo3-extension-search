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
use Maispace\MaiSearch\Http\SseStream;
use Maispace\MaiSearch\Service\ProviderRegistry;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Token-by-token RAG answer synthesis over Server-Sent Events (SPEC-v2.md
 * Phase 7 addendum). A plain PSR-15 middleware rather than an Extbase action —
 * ActionController buffers the full response body before it's sent, which
 * defeats streaming; see Classes/Http/SseStream.php for how the response body
 * bypasses that buffering via TYPO3 core's SelfEmittableStreamInterface.
 *
 * Deliberately reads settings from RagConfiguration (Extension Configuration),
 * not TypoScript plugin settings — this middleware runs before any Extbase
 * request/TypoScript Frontend Controller context is resolved.
 *
 * Consumed by Resources/Public/JavaScript/search-stream.js, wired up by
 * SearchController::resultsAction() only when ragEnabled + streaming are on.
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

        $payload = $this->decodeJsonBody($request);
        $query = trim((string) ($payload['query'] ?? ''));

        if ($query === '') {
            return $this->errorResponse('Missing query.', 400);
        }

        $type = isset($payload['type']) && $payload['type'] !== '' ? (string) $payload['type'] : null;
        $history = ConversationHistory::fromJson((string) ($payload['history'] ?? ''));

        $chatProvider = $this->providerRegistry->getActiveChatProvider();
        if (!$chatProvider instanceof StreamingChatProviderInterface) {
            return $this->errorResponse('The active chat provider does not support streaming.', 501);
        }

        $context = $this->resolveContext($query, $this->resolveLanguage($request), $type);

        if ($context === []) {
            return $this->errorResponse('No results to answer from.', 404);
        }

        return $this->streamResponse($chatProvider, $query, $context, $history);
    }

    /**
     * @param SearchResult[] $context
     */
    private function streamResponse(
        StreamingChatProviderInterface $chatProvider,
        string $query,
        array $context,
        ConversationHistory $history,
    ): ResponseInterface {
        $chatRequest = $this->synthesisService->buildChatRequest($query, $context, $history);
        $sources = $this->buildSources($context);

        $answer = '';
        $tokens = (static function () use ($chatProvider, $chatRequest, &$answer): \Generator {
            foreach ($chatProvider->stream($chatRequest) as $token) {
                $answer .= $token;

                yield $token;
            }
        })();

        $finalize = function () use (&$answer, $sources, $history, $query): array {
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
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody(new SseStream($tokens, $finalize));
    }

    /**
     * @return SearchResult[]
     */
    private function resolveContext(string $query, ?SiteLanguage $language, ?string $type): array
    {
        if (!$this->ragConfiguration->isRerankEnabled()) {
            return $this->searchService->search(
                $query,
                $this->ragConfiguration->getTopN(),
                0,
                $language,
                true,
                $type,
            )->results;
        }

        return $this->ragContextResolver->resolve(
            $query,
            $language,
            $type,
            $this->ragConfiguration->getTopN(),
            $this->ragConfiguration->getRerankWidth(),
        );
    }

    /**
     * @param SearchResult[] $context
     * @return list<array{title: string, url: string, type: string, score: float}>
     */
    private function buildSources(array $context): array
    {
        return array_map(
            static fn(SearchResult $result): array => [
                'title' => $result->title,
                'url' => $result->url,
                'type' => $result->type,
                'score' => $result->score,
            ],
            $context,
        );
    }

    private function resolveLanguage(ServerRequestInterface $request): ?SiteLanguage
    {
        $language = $request->getAttribute('language');

        return $language instanceof SiteLanguage ? $language : null;
    }
}
