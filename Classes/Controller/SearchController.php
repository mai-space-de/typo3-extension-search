<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Controller;

use Maispace\MaiBase\Controller\Traits\PageRendererTrait;
use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Configuration\SuggestConfiguration;
use Maispace\MaiSearch\Domain\Model\ConversationHistory;
use Maispace\MaiSearch\Domain\Service\RagContextResolver;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use Maispace\MaiSearch\Http\RagApiSupport;
use Maispace\MaiSearch\Service\ProviderAvailabilityService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Attribute\RateLimit;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class SearchController extends ActionController
{
    use PageRendererTrait;

    public function __construct(
        private readonly SearchService $searchService,
        private readonly SearchSynthesisService $synthesisService,
        private readonly RagContextResolver $ragContextResolver,
        private readonly RagConfiguration $ragConfiguration,
        private readonly SuggestConfiguration $suggestConfiguration,
        private readonly ProviderAvailabilityService $providerAvailability,
        private readonly RagApiSupport $ragApiSupport,
    ) {}

    public function injectPageRenderer(PageRenderer $pageRenderer): void
    {
        $this->pageRenderer = $pageRenderer;
    }

    public function injectAssetCollector(AssetCollector $assetCollector): void
    {
        $this->assetCollector = $assetCollector;
    }

    /**
     * Legacy entry point — plugin registers `results` only; delegates for BC.
     */
    public function formAction(): ResponseInterface
    {
        return $this->resultsAction();
    }

    /**
     * @param string $history JSON-encoded ConversationHistory::toArray() payload, round-tripped through a
     *                        hidden form field so multi-turn RAG follow-ups work without server-side session
     *                        state (SPEC-v2.md Phase 7 addendum). Empty on the very first query of a session.
     */
    #[RateLimit(limit: 30, interval: '1 minute')]
    public function resultsAction(string $query = '', string $type = '', int $page = 1, string $history = ''): ResponseInterface
    {
        $ragEnabled = $this->resolveRagEnabled();
        $chatAvailable = !$ragEnabled || $this->providerAvailability->isChatProviderAvailable();
        $suggestEnabled = $this->suggestConfiguration->isEnabled();
        $perPage = max(1, (int) ($this->settings['resultsPerPage'] ?? 20));
        $page = max(1, $page);
        $typeFilter = $type !== '' ? $type : null;
        $conversationHistory = $this->decodeHistory($history);
        $language = $this->resolveCurrentLanguage();
        $languageCode = $this->ragApiSupport->languageCode($language);

        if ($ragEnabled && $chatAvailable && $this->ragConfiguration->isStreamingEnabled()) {
            $this->addJsFile('mai_search_stream', 'EXT:mai_search/Resources/Public/JavaScript/search-stream.js');
        }

        if ($suggestEnabled) {
            $this->addJsFile('mai_search_suggest', 'EXT:mai_search/Resources/Public/JavaScript/search-suggest.js');
        }

        if (trim($query) === '') {
            $this->view->assignMultiple([
                'ragEnabled' => $ragEnabled,
                'ragProviderUnavailable' => $ragEnabled && !$chatAvailable,
                'suggestEnabled' => $suggestEnabled,
                'query' => $query,
                'type' => $typeFilter,
                'page' => $page,
                'historyPayload' => $history,
                'languageCode' => $languageCode,
                'ragContextPayload' => '[]',
            ]);

            return $this->htmlResponse();
        }

        $offset = ($page - 1) * $perPage;

        $resultPage = $this->searchService->search(
            $query,
            $perPage,
            $offset,
            $language,
            $ragEnabled && $chatAvailable,
            type: $typeFilter,
        );

        $ragOnPageOne = $ragEnabled && $chatAvailable && $page === 1 && $resultPage->results !== [];
        $streamingActive = $ragOnPageOne && $this->ragConfiguration->isStreamingEnabled();

        $synthesisResult = null;
        $historyPayload = $history;
        $ragContextPayload = '[]';

        if ($ragOnPageOne) {
            $context = $this->ragConfiguration->isRerankEnabled()
                ? $this->ragContextResolver->resolve(
                    $query,
                    $language,
                    $typeFilter,
                    $this->ragConfiguration->getTopN(),
                    $this->ragConfiguration->getRerankWidth(),
                )
                : array_slice($resultPage->results, 0, $this->ragConfiguration->getTopN());

            $ragContextPayload = json_encode(
                $this->ragApiSupport->serializeContext($context, $this->ragConfiguration->getTopN()),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );

            if (!$streamingActive) {
                $synthesisResult = $this->synthesisService->synthesise($query, $context, true, $conversationHistory);
                $historyPayload = $this->encodeHistory($this->appendTurn($conversationHistory, $query, $synthesisResult->answer));
            }
        }

        $this->view->assignMultiple([
            'query' => $query,
            'type' => $typeFilter,
            'results' => $resultPage->results,
            'count' => $resultPage->total,
            'facets' => $resultPage->facets,
            'page' => $resultPage->page,
            'perPage' => $resultPage->perPage,
            'totalPages' => $resultPage->getTotalPages(),
            'prevPage' => $resultPage->page > 1 ? $resultPage->page - 1 : null,
            'nextPage' => $resultPage->page < $resultPage->getTotalPages() ? $resultPage->page + 1 : null,
            'ragEnabled' => $ragEnabled,
            'ragProviderUnavailable' => $ragEnabled && !$chatAvailable,
            'suggestEnabled' => $suggestEnabled,
            'synthesisResult' => $synthesisResult,
            'ragStreaming' => $streamingActive,
            'historyPayload' => $historyPayload,
            'languageCode' => $languageCode,
            'ragContextPayload' => $ragContextPayload,
        ]);

        return $this->htmlResponse();
    }

    private function resolveCurrentLanguage(): ?SiteLanguage
    {
        $language = $this->request->getAttribute('language');

        return $language instanceof SiteLanguage ? $language : null;
    }

    private function resolveRagEnabled(): bool
    {
        return (bool) ($this->settings['ragEnabled'] ?? false);
    }

    private function decodeHistory(string $history): ConversationHistory
    {
        return ConversationHistory::fromJson($history);
    }

    private function appendTurn(ConversationHistory $history, string $query, string $answer): ConversationHistory
    {
        return $history
            ->withUserMessage($query)
            ->withAssistantMessage($answer)
            ->truncated($this->ragConfiguration->getHistoryMaxTurns());
    }

    private function encodeHistory(ConversationHistory $history): string
    {
        return $history->toJson();
    }
}
