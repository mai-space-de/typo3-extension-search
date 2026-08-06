<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Meilisearch;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Meilisearch implementation of SearchBackendInterface (SPEC-v2.md Phase 2b).
 *
 * One index per language holds every record type, with `type` as a
 * filterable attribute — mirrors SolrBackend's shape (one query, all types,
 * type as a facet) rather than mai_meilisearch's original one-index-per-type
 * convention (decided explicitly; see IndexNameResolver).
 *
 * getCapabilities()->supportsVectorSearch is false: hybrid search requires
 * pushing an `embedders` config derived from the active EmbeddingProviderInterface
 * (a Meilisearch-specific mapping deliberately kept off that interface in
 * Phase 2a) plus per-document vectors. That wiring is deferred to Phase 7
 * alongside the chunk-index RAG upgrade, not half-built here.
 */
final class MeilisearchBackend implements SearchBackendInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const string TYPE_FILTER_ATTRIBUTE = 'type';

    /**
     * @var array<string, true>
     */
    private array $ensuredIndexes = [];

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly IndexNameResolver $indexNameResolver,
        private readonly ResultFormatterRegistry $resultFormatterRegistry,
    ) {}

    public function getIdentifier(): string
    {
        return 'meilisearch';
    }

    public function getCapabilities(): BackendCapabilities
    {
        return new BackendCapabilities(
            supportsRealtimeIndexing: true,
            supportsFacets: true,
            supportsVectorSearch: false,
            hasComparableRelevanceScore: true,
        );
    }

    public function search(SearchRequest $request): SearchResultPage
    {
        $limit = $request->limit;
        $page = $limit > 0 ? (int) floor($request->offset / $limit) + 1 : 1;

        if (trim($request->query) === '') {
            return new SearchResultPage([], 0, [], $page, $limit);
        }

        if ($request->ragEnabled) {
            $this->logger?->info('MeilisearchBackend does not support hybrid/RAG search yet; running plain text search.');
        }

        $indexUid = $this->indexNameResolver->forLanguage($this->resolveLanguageCode($request->language));

        $searchParameters = [
            'limit' => $limit,
            'offset' => $request->offset,
            'facets' => [self::TYPE_FILTER_ATTRIBUTE],
            'showRankingScore' => true,
        ];

        $normalizedType = $this->normalizeTypeFilter($request->type);
        if ($normalizedType !== null) {
            $searchParameters['filter'] = sprintf('%s = "%s"', self::TYPE_FILTER_ATTRIBUTE, $normalizedType);
        }

        $response = $this->connectionFactory->getClient()->search($indexUid, $request->query, $searchParameters);

        return new SearchResultPage(
            $this->buildResults($response),
            $response->estimatedTotalHits,
            $response->facetDistribution[self::TYPE_FILTER_ATTRIBUTE] ?? [],
            $page,
            $limit,
        );
    }

    public function indexDocument(SearchDocumentInput $document): void
    {
        $languageCode = $document->additionalFields['languageCode'] ?? null;
        $indexUid = $this->indexNameResolver->forLanguage(is_string($languageCode) ? $languageCode : null);

        $client = $this->connectionFactory->getClient();
        $this->ensureIndex($client, $indexUid);

        $client->addDocuments($indexUid, [$this->toMeilisearchDocument($document)]);
    }

    public function removeDocument(string $type, int $uid): void
    {
        $indexUid = $this->indexNameResolver->forLanguage(null);

        $this->connectionFactory->getClient()->deleteDocument($indexUid, $type . '-' . $uid);
    }

    private function ensureIndex(MeilisearchClientInterface $client, string $indexUid): void
    {
        if (isset($this->ensuredIndexes[$indexUid])) {
            return;
        }

        $client->ensureIndex($indexUid, [self::TYPE_FILTER_ATTRIBUTE]);
        $this->ensuredIndexes[$indexUid] = true;
    }

    private function toMeilisearchDocument(SearchDocumentInput $input): MeilisearchDocument
    {
        $document = (new MeilisearchDocument())
            ->withField('id', $input->type . '-' . $input->uid)
            ->withField('type', $input->type)
            ->withField('title', $input->title)
            ->withField('content', $input->content)
            ->withField('url', $input->url)
            ->withField('uid', $input->uid)
            ->withField('crdate', $input->crdate->getTimestamp())
            ->withField('boost', $input->boost)
            ->withField('rootline', implode(' | ', $input->rootline));

        foreach ($input->additionalFields as $key => $value) {
            if ($key === 'languageCode') {
                continue;
            }

            $document = $document->withField($key, $value);
        }

        return $document;
    }

    private function normalizeTypeFilter(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $normalized = trim($type);
        if ($normalized === '' || !preg_match('/^[a-z0-9_-]+$/i', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function resolveLanguageCode(?SiteLanguage $language): ?string
    {
        return $language?->getLocale()->getLanguageCode();
    }

    /**
     * @return list<SearchResult>
     */
    private function buildResults(SearchResponse $response): array
    {
        $results = [];

        foreach ($response->hits as $hit) {
            $type = $hit['type'] ?? '';
            if ($type === '') {
                continue;
            }

            $formatter = $this->resultFormatterRegistry->getFormatter($type);

            if ($formatter === null) {
                continue;
            }

            $results[] = $formatter->formatResult($this->toSolrShapedArray($hit));
        }

        return $results;
    }

    /**
     * Existing SearchResultFormatterInterface implementations (NewsIndexer,
     * FaqIndexer, ...) read Solr's `_s`/`_t`-suffixed field names directly.
     * Translating Meilisearch's plain-field hits into that same shape here —
     * rather than editing all ten indexers — keeps every existing formatter
     * working unchanged. Revisit once "reconcile the indexer contract"
     * generalizes SearchResultFormatterInterface itself.
     *
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function toSolrShapedArray(array $hit): array
    {
        return [
            'type_s' => $hit['type'] ?? '',
            'title_s' => $hit['title'] ?? '',
            'title_t' => $hit['title'] ?? '',
            'content_t' => $hit['content'] ?? '',
            'url_s' => $hit['url'] ?? '',
            'uid_i' => $hit['uid'] ?? 0,
            'crdate_dt' => isset($hit['crdate'])
                ? (new \DateTime())->setTimestamp((int) $hit['crdate'])->format('Y-m-d\TH:i:s\Z')
                : null,
            'score' => $hit['_rankingScore'] ?? 0.0,
        ];
    }
}
