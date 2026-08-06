<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\IndexedSearch;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\IndexedSearch\Domain\Repository\IndexSearchRepository;
use TYPO3\CMS\IndexedSearch\Lexer;
use TYPO3\CMS\IndexedSearch\Utility\IndexedSearchUtility;

/**
 * indexed_search implementation of SearchBackendInterface (SPEC-v2.md Phase 1)
 * — the zero-infrastructure default backend. TYPO3 core indexes rendered page
 * HTML by phash, not structured record fields, so this backend has real,
 * permanent limitations reflected in getCapabilities():
 *
 * - No realtime per-record indexing: indexDocument()/removeDocument() are
 *   no-ops (logged), matching supportsRealtimeIndexing = false. Search
 *   coverage depends on whatever core's own page-render-time indexing (or a
 *   future crawl trigger, see SPEC-v2.md Phase 3) has already put in the index.
 * - No facets, no vector search.
 * - "score" is a page-relevance rank derived from result order, not
 *   comparable to Solr/Meilisearch scores (hasComparableRelevanceScore = false).
 */
final class IndexedSearchBackend implements SearchBackendInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const int DEFAULT_LIMIT = 20;

    public function __construct(
        private readonly IndexSearchRepository $searchRepository,
        private readonly SiteFinder $siteFinder,
        private readonly Lexer $lexer,
    ) {}

    public function getIdentifier(): string
    {
        return 'indexed_search';
    }

    public function getCapabilities(): BackendCapabilities
    {
        return new BackendCapabilities(
            supportsRealtimeIndexing: false,
            supportsFacets: false,
            supportsVectorSearch: false,
            hasComparableRelevanceScore: false,
        );
    }

    public function search(SearchRequest $request): SearchResultPage
    {
        $limit = $request->limit > 0 ? $request->limit : self::DEFAULT_LIMIT;
        $page = (int) floor($request->offset / $limit) + 1;

        if (trim($request->query) === '') {
            return new SearchResultPage([], 0, [], $page, $limit);
        }

        if ($request->type !== null) {
            $this->logger?->info(
                'IndexedSearchBackend does not support type filtering; ignoring type "{type}".',
                ['type' => $request->type],
            );
        }

        $this->searchRepository->initialize(
            settings: [],
            searchData: [
                'languageUid' => $request->language?->getLanguageId() ?? 0,
                'pointer' => $page - 1,
                'numberOfResults' => $limit,
            ],
            externalParsers: [],
            searchRootPageIdList: -1,
        );

        $result = $this->searchRepository->doSearch($this->buildSearchWords($request->query), -1);

        if ($result === false) {
            return new SearchResultPage([], 0, [], $page, $limit);
        }

        return new SearchResultPage(
            $this->buildResults($result['resultRows']),
            $result['count'],
            [],
            $page,
            $limit,
        );
    }

    public function indexDocument(SearchDocumentInput $document): void
    {
        $this->logger?->warning(
            'IndexedSearchBackend does not support realtime indexing; record "{type}-{uid}" was not written.'
                . ' Search coverage depends on core\'s own page-render-time indexing.',
            ['type' => $document->type, 'uid' => $document->uid],
        );
    }

    public function removeDocument(string $type, int $uid): void
    {
        $this->logger?->warning(
            'IndexedSearchBackend does not support realtime removal; record "{type}-{uid}" was left in the index.',
            ['type' => $type, 'uid' => $uid],
        );
    }

    /**
     * Mirrors core's own SearchController::getSearchWords()/procSearchWordsByLexer()
     * (both protected, not reusable directly) for the simple, non-sentence case.
     *
     * @return list<array{sword: string, oper: string}>
     */
    private function buildSearchWords(string $query): array
    {
        $exploded = IndexedSearchUtility::getExplodedSearchString($query, 'AND', [
            ['+', 'AND'],
            ['|', 'OR'],
            ['-', 'AND NOT'],
        ]);

        $words = [];
        foreach ($exploded as $wordDef) {
            if (str_contains($wordDef['sword'], ' ')) {
                $words[] = $wordDef;

                continue;
            }

            foreach ($this->lexer->split2Words($wordDef['sword']) as $word) {
                $words[] = ['sword' => $word, 'oper' => $wordDef['oper']];
            }
        }

        return $words;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<SearchResult>
     */
    private function buildResults(array $rows): array
    {
        $results = [];

        foreach ($rows as $row) {
            $url = $this->resolveUrl((int) ($row['data_page_id'] ?? 0));
            if ($url === null) {
                continue;
            }

            $results[] = new SearchResult(
                type: 'page',
                title: (string) ($row['item_title'] ?? ''),
                snippet: (string) ($row['item_description'] ?? ''),
                url: $url,
                icon: 'page-icon',
                date: isset($row['item_crdate']) ? (new \DateTime())->setTimestamp((int) $row['item_crdate']) : null,
                score: 1 / max(1, (int) ($row['result_number'] ?? 1)),
            );
        }

        return $results;
    }

    private function resolveUrl(int $pageId): ?string
    {
        if ($pageId <= 0) {
            return null;
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);

            return (string) $site->getRouter()->generateUri($pageId);
        } catch (\Exception) {
            return null;
        }
    }
}
