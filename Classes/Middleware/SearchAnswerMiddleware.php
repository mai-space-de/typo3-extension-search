<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Middleware;

use Maispace\MaiBase\Middleware\Api\AbstractApiMiddleware;
use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Configuration\RagPromptFactory;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Model\ConversationHistory;
use Maispace\MaiSearch\Domain\Service\RagContextResolver;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use Maispace\MaiSearch\Http\RagApiSupport;
use Maispace\MaiSearch\Service\ProviderAvailabilityService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Synchronous RAG answer JSON API (proxy-safe fallback for SSE).
 *
 * Used when streaming is off, fails, or times out. Shares prompt building with
 * SearchStreamMiddleware via SearchSynthesisService and benefits from
 * AnswerCacheService inside synthesise().
 */
class SearchAnswerMiddleware extends AbstractApiMiddleware
{
    private const API_PATH = '/api/search/answer';

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        private readonly SearchService $searchService,
        private readonly RagContextResolver $ragContextResolver,
        private readonly SearchSynthesisService $synthesisService,
        private readonly RagConfiguration $ragConfiguration,
        private readonly ProviderAvailabilityService $providerAvailability,
        private readonly RagApiSupport $ragApiSupport,
        private readonly RagPromptFactory $ragPromptFactory,
    ) {
        parent::__construct($responseFactory, $streamFactory);
    }

    public function shouldHandle(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getPath() === self::API_PATH && $this->isMethod($request, 'POST');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
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

        $context = $this->resolveContext($payload, $query, $language, $type);
        if ($context === []) {
            return $this->errorResponse('No results to answer from.', 404);
        }

        $synthesis = $this->synthesisService->synthesise(
            $query,
            $context,
            true,
            $history,
            $this->ragPromptFactory->fromSite($this->ragApiSupport->resolveSite($request)),
        );
        $updatedHistory = $history
            ->withUserMessage($query)
            ->withAssistantMessage($synthesis->answer)
            ->truncated($this->ragConfiguration->getHistoryMaxTurns());

        return $this->jsonResponse([
            'answer' => $synthesis->answer,
            'sources' => $this->ragApiSupport->buildSources($context),
            'history' => $updatedHistory->toArray(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return SearchResult[]
     */
    private function resolveContext(
        array $payload,
        string $query,
        ?SiteLanguage $language,
        ?string $type,
    ): array {
        $fromClient = $this->ragApiSupport->parseContext($payload['context'] ?? null);
        if ($fromClient !== [] && !$this->ragConfiguration->isRerankEnabled()) {
            return $fromClient;
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
