<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory as MeilisearchConnectionFactory;
use Maispace\MaiSearch\Backend\Meilisearch\IndexNameResolver as MeilisearchIndexNameResolver;
use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\Document;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Domain\Solr\SearchQuery;
use Maispace\MaiSearch\Domain\Solr\SolrClientInterface;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\ContentHashService;
use Maispace\MaiSearch\Service\EmbeddingTextTruncator;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use Maispace\MaiSearch\Service\OrphanChunkCleanupService;
use Maispace\MaiSearch\Service\ProviderRegistry;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class IndexManagementService implements SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const string MEILISEARCH_IDENTIFIER = 'meilisearch';

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly ProviderRegistry $providerRegistry,
        private readonly ?IndexerRegistry $indexerRegistry = null,
        private readonly ?SiteFinder $siteFinder = null,
        private readonly ?AnswerCacheService $answerCacheService = null,
        private readonly EmbeddingTextTruncator $embeddingTextTruncator = new EmbeddingTextTruncator(),
        private readonly ?ContentHashService $contentHashService = null,
        private readonly ?OrphanChunkCleanupService $chunkHashService = null,
        // Not BackendRegistry itself: BackendRegistryCompilerPass eagerly wires every
        // registered SearchBackendInterface (including SolrBackend, which depends on
        // *this* service) into BackendRegistry's constructor calls — injecting
        // BackendRegistry here would be circular. Extension configuration is the same
        // primitive BackendRegistry::resolveActiveIdentifier() itself reads, without
        // going through the object graph that creates the cycle.
        private readonly ?ExtensionConfiguration $extensionConfiguration = null,
        private readonly ?MeilisearchConnectionFactory $meilisearchConnectionFactory = null,
        private readonly ?MeilisearchIndexNameResolver $meilisearchIndexNameResolver = null,
    ) {}

    public function addDocument(Document $document, ?SiteLanguage $language = null, bool $contentChanged = true): void
    {
        $connection = $this->connectionFactory->getConnection($language);
        $this->addEmbeddingToDocument($document, $contentChanged, $connection);

        $connection->addDocuments([$document]);
        $connection->commit(false, false);
    }

    /**
     * @param string|null $languageCode ISO 639-1 code for indexers working with
     *                                   IndexingContext::$languageCode strings
     * @param bool $contentChanged Whether the extracted content actually changed since
     *   the last time this document was indexed (SearchDocumentInput::$contentChanged,
     *   SPEC-v3-caching-chunking.md Phase B). When false, addEmbeddingToDocument()
     *   skips the embedding-provider call and carries the previously stored vector
     *   forward instead of re-requesting an identical one.
     */
    public function addDocumentForLanguageCode(Document $document, ?string $languageCode = null, bool $contentChanged = true): void
    {
        if ($languageCode !== null) {
            $connection = $this->connectionFactory->getConnectionForLanguageCode($languageCode);
        } else {
            $connection = $this->connectionFactory->getConnection();
        }

        $this->addEmbeddingToDocument($document, $contentChanged, $connection);

        $connection->addDocuments([$document]);
        $connection->commit(false, false);
    }

    public function deleteRecord(string $type, int $uid, ?SiteLanguage $language = null): void
    {
        $id = $type . '-' . $uid;
        $connection = $this->connectionFactory->getConnection($language);
        $connection->deleteByQuery('id:' . $id);
        $connection->commit(false, false);
    }

    /**
     * Deletes a single document by its exact Solr `id` field — used for chunk
     * documents (SPEC-v3-caching-chunking.md Phase D), whose id doesn't fit the
     * "{type}-{uid}" shape deleteRecord() assumes.
     */
    public function deleteById(string $id, ?SiteLanguage $language = null): void
    {
        $connection = $this->connectionFactory->getConnection($language);
        $connection->deleteByQuery('id:' . $id);
        $connection->commit(false, false);
    }

    /**
     * Deletes every document matching an arbitrary Solr query — used to remove
     * all chunks of one parent document in a single call
     * (ChunkSearchBackendInterface::removeChunks()).
     */
    public function deleteByQuery(string $solrQuery, ?SiteLanguage $language = null): void
    {
        $connection = $this->connectionFactory->getConnection($language);
        $connection->deleteByQuery($solrQuery);
        $connection->commit(false, false);
    }

    public function deleteByType(string $type, ?SiteLanguage $language = null): void
    {
        $connection = $this->connectionFactory->getConnection($language);
        $connection->deleteByType($type);
        $connection->commit(false, false);
    }

    public function clearIndex(?SiteLanguage $language = null): void
    {
        $connection = $this->connectionFactory->getConnection($language);
        $connection->deleteByQuery('*:*');
        $connection->commit(false, false);

        $this->invalidateAfterClear();
    }

    /**
     * Clear every configured language core — or, when Meilisearch is the active
     * backend, every language (and chunk) index instead. The backend module's
     * "Clear Index" button must act on whichever store is actually serving
     * search right now; clearing Solr while Meilisearch is active would silently
     * no-op against a store nothing reads from, leaving the real index untouched.
     */
    public function clearAllCores(): void
    {
        if ($this->isMeilisearchActive()) {
            $this->clearAllMeilisearchIndexes();

            return;
        }

        $coreMapping = $this->connectionFactory->getCoreMapping();

        if ($coreMapping === []) {
            $this->clearIndex();

            return;
        }

        foreach (array_keys($coreMapping) as $languageCode) {
            $connection = $this->connectionFactory->getConnectionForLanguageCode((string) $languageCode);
            $connection->deleteByQuery('*:*');
            $connection->commit(false, false);
        }

        $this->invalidateAfterClear();
    }

    /**
     * Shared cleanup after any full index clear — three things go stale together:
     *
     * - Any cached answer built from documents that just got deleted (SPEC-v3-
     *   caching-chunking.md Phase C, gap G5) — unlike a save-triggered
     *   invalidation, there's no single URL to target.
     * - Document content hashes (ContentHashService) and chunk hashes
     *   (OrphanChunkCleanupService): both gate re-embedding on "did the content
     *   change since last indexed" — after a clear, the backend has *nothing*
     *   indexed, so every hash on record here is now a false "already indexed,
     *   skip re-embedding" that would leave a freshly reindexed backend without
     *   any vectors at all.
     */
    private function invalidateAfterClear(): void
    {
        $this->answerCacheService?->invalidateAll();
        $this->contentHashService?->removeAllHashes();
        $this->chunkHashService?->removeAllHashes();
    }

    /**
     * Run a full re-index across all configured cores and registered indexers.
     *
     * Shared logic extracted from FullReindexTask so both the scheduler task
     * and the backend module can trigger re-indexing through the same path.
     */
    public function reindexAll(): void
    {
        if ($this->indexerRegistry === null) {
            return;
        }

        // Eagerly invalidated up front rather than left to gradual per-record
        // invalidation (AbstractIndexer::trackContentChange()) — a full reindex is
        // exactly the "treat the answer cache as needing revalidation" signal the
        // user asked for (SPEC-v3-caching-chunking.md Phase C, gap G5), even though
        // Phase B's hash-skip means most individual records won't re-trigger it
        // themselves.
        $this->answerCacheService?->invalidateAll();

        $coreMapping = $this->connectionFactory->getCoreMapping();
        $indexers = $this->indexerRegistry->getAll();

        if ($coreMapping === []) {
            $context = GeneralUtility::makeInstance(
                IndexingContext::class,
                'core_en',
                100,
                0,
            );

            foreach ($indexers as $indexer) {
                $indexer->indexAll($context);
            }

            return;
        }

        $languages = $this->resolveSiteLanguages();

        foreach ($coreMapping as $languageCode => $core) {
            $context = GeneralUtility::makeInstance(
                IndexingContext::class,
                $core,
                100,
                0,
                $languageCode,
                $this->resolveLanguageUid((string) $languageCode, $languages),
            );

            foreach ($indexers as $indexer) {
                $indexer->indexAll($context);
            }
        }
    }

    /**
     * Repopulate the index queue for all registered indexers instead of indexing directly.
     *
     * This is the queue-based alternative to reindexAll(): instead of pushing documents
     * directly to the backend, it enqueues all records for async processing by
     * IndexQueueProcessor. This ensures reindexing and realtime sync share exactly
     * one write path (the queue).
     *
     * @param IndexQueueRepository $indexQueueRepository The queue repository to populate
     * @return int Number of jobs enqueued
     */
    public function repopulateQueue(IndexQueueRepository $indexQueueRepository): int
    {
        if ($this->indexerRegistry === null) {
            return 0;
        }

        // See reindexAll()'s identical call for why this is eager rather than
        // left to gradual per-record invalidation.
        $this->answerCacheService?->invalidateAll();

        // A full reindex supersedes whatever was queued by every previous one —
        // clearing first is what makes the backend module's queue counts mean
        // "progress of this reindex" instead of a growing lifetime total (see
        // IndexQueueRepository::clearAll()'s docblock).
        $indexQueueRepository->clearAll();

        $coreMapping = $this->connectionFactory->getCoreMapping();
        $indexers = $this->indexerRegistry->getAll();
        $enqueued = 0;

        if ($coreMapping === []) {
            $context = GeneralUtility::makeInstance(
                IndexingContext::class,
                'core_en',
                100,
                0,
            );

            foreach ($indexers as $indexer) {
                $table = $indexer->getTableName();
                if ($table === '') {
                    continue;
                }

                foreach ($indexer->getRecordUidsForReindex($context) as $uid) {
                    $indexQueueRepository->enqueue($table, $uid, IndexQueueRepository::OPERATION_INDEX);
                    $enqueued++;
                }
            }

            return $enqueued;
        }

        $languages = $this->resolveSiteLanguages();

        foreach ($coreMapping as $languageCode => $core) {
            $context = GeneralUtility::makeInstance(
                IndexingContext::class,
                $core,
                100,
                0,
                $languageCode,
                $this->resolveLanguageUid((string) $languageCode, $languages),
            );

            foreach ($indexers as $indexer) {
                $table = $indexer->getTableName();
                if ($table === '') {
                    continue;
                }

                foreach ($indexer->getRecordUidsForReindex($context) as $uid) {
                    $indexQueueRepository->enqueue($table, $uid, IndexQueueRepository::OPERATION_INDEX);
                    $enqueued++;
                }
            }
        }

        return $enqueued;
    }

    /**
     * @return SiteLanguage[]
     */
    private function resolveSiteLanguages(): array
    {
        try {
            $siteFinder = $this->siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
            $sites = $siteFinder->getAllSites();
        } catch (\Throwable) {
            return [];
        }

        $site = reset($sites);

        return $site !== false ? $site->getLanguages() : [];
    }

    /**
     * @param SiteLanguage[] $languages
     */
    private function resolveLanguageUid(string $languageCode, array $languages): ?int
    {
        foreach ($languages as $language) {
            if ($language->getLocale()->getLanguageCode() === $languageCode) {
                return $language->getLanguageId();
            }
        }

        return null;
    }

    /**
     * Query the active backend for index statistics across all configured
     * language cores/indexes.
     *
     * Returns an array with per-core stats containing total document count
     * and per-type breakdowns via faceting on the type field.
     *
     * @return array{
     *     cores: array<string, array{core: string, totalDocuments: int, types: array<string, int>}>,
     *     totalDocuments: int,
     * }
     */
    public function getIndexStats(): array
    {
        if ($this->isMeilisearchActive()) {
            return $this->getMeilisearchIndexStats();
        }

        $coreMapping = $this->connectionFactory->getCoreMapping();

        // Single default core
        if ($coreMapping === []) {
            $stats = $this->queryCoreStats('core_en');
            $cores = ['en' => array_merge(['core' => 'core_en'], $stats)];

            return [
                'cores' => $cores,
                'totalDocuments' => $stats['totalDocuments'],
            ];
        }

        // Multiple language cores
        $cores = [];
        $totalDocuments = 0;

        foreach ($coreMapping as $languageCode => $core) {
            $stats = $this->queryCoreStats($core);
            $cores[$languageCode] = array_merge(['core' => $core], $stats);
            $totalDocuments += $stats['totalDocuments'];
        }

        return [
            'cores' => $cores,
            'totalDocuments' => $totalDocuments,
        ];
    }

    /**
     * Per-language core/index identifiers for the active backend — Solr's are
     * statically configured (ext_conf `solr.coreMapping`), Meilisearch's are
     * computed per language from the site's actually configured languages
     * since there's no equivalent static setting for it.
     *
     * @return array<string, string>
     */
    public function getCoreMapping(): array
    {
        if ($this->isMeilisearchActive()) {
            return $this->getMeilisearchCoreMapping();
        }

        return $this->connectionFactory->getCoreMapping();
    }

    /**
     * Query a single Solr core for document counts and per-type breakdown.
     *
     * @return array{totalDocuments: int, types: array<string, int>}
     */
    private function queryCoreStats(string $core): array
    {
        try {
            $languageCode = $this->resolveLanguageCodeFromCore($core);
            $connection = $this->connectionFactory->getConnectionForLanguageCode($languageCode);

            $query = (new SearchQuery())
                ->setQuery('*:*')
                ->setRows(0)
                ->addFacetField('type', 'type_s');

            $response = $connection->search($query);

            return [
                'totalDocuments' => $response->getNumFound(),
                'types' => $response->getFacetCountsFor('type'),
            ];
        } catch (\Throwable) {
            // Solr is unreachable — return empty stats rather than breaking the backend module.
            return [
                'totalDocuments' => 0,
                'types' => [],
            ];
        }
    }

    private function resolveLanguageCodeFromCore(string $core): string
    {
        if (preg_match('/^core_([a-z]{2,3})$/', $core, $matches) === 1) {
            return $matches[1];
        }

        return 'en';
    }

    private function isMeilisearchActive(): bool
    {
        if ($this->extensionConfiguration === null
            || $this->meilisearchConnectionFactory === null
            || $this->meilisearchIndexNameResolver === null
        ) {
            return false;
        }

        try {
            $settings = $this->extensionConfiguration->get('mai_search');
        } catch (\Exception) {
            return false;
        }

        return ($settings['activeBackend'] ?? null) === self::MEILISEARCH_IDENTIFIER;
    }

    /**
     * @return array{
     *     cores: array<string, array{core: string, totalDocuments: int, types: array<string, int>}>,
     *     totalDocuments: int,
     * }
     */
    private function getMeilisearchIndexStats(): array
    {
        $cores = [];
        $totalDocuments = 0;

        foreach ($this->resolveMeilisearchLanguageCodes() as $languageCode) {
            $key = $languageCode ?? 'default';
            $indexUid = $this->meilisearchIndexNameResolver->forLanguage($languageCode);
            $stats = $this->queryMeilisearchIndexStats($indexUid);

            $cores[$key] = array_merge(['core' => $indexUid], $stats);
            $totalDocuments += $stats['totalDocuments'];
        }

        return [
            'cores' => $cores,
            'totalDocuments' => $totalDocuments,
        ];
    }

    /**
     * @return array{totalDocuments: int, types: array<string, int>}
     */
    private function queryMeilisearchIndexStats(string $indexUid): array
    {
        try {
            $response = $this->meilisearchConnectionFactory->getClient()->search(
                $indexUid,
                '',
                ['limit' => 0, 'facets' => ['type']],
            );

            return [
                'totalDocuments' => $response->estimatedTotalHits,
                'types' => $response->facetDistribution['type'] ?? [],
            ];
        } catch (\Throwable) {
            // Index doesn't exist yet or Meilisearch is unreachable — return empty
            // stats rather than breaking the backend module.
            return [
                'totalDocuments' => 0,
                'types' => [],
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    private function getMeilisearchCoreMapping(): array
    {
        $mapping = [];

        foreach ($this->resolveMeilisearchLanguageCodes() as $languageCode) {
            $mapping[$languageCode ?? 'default'] = $this->meilisearchIndexNameResolver->forLanguage($languageCode);
        }

        return $mapping;
    }

    private function clearAllMeilisearchIndexes(): void
    {
        $client = $this->meilisearchConnectionFactory->getClient();

        foreach ($this->resolveMeilisearchLanguageCodes() as $languageCode) {
            $client->deleteAllDocuments($this->meilisearchIndexNameResolver->forLanguage($languageCode));
            $client->deleteAllDocuments($this->meilisearchIndexNameResolver->forChunks($languageCode));
        }

        $this->invalidateAfterClear();
    }

    /**
     * @return list<string|null>
     */
    private function resolveMeilisearchLanguageCodes(): array
    {
        $codes = [];

        foreach ($this->resolveSiteLanguages() as $language) {
            $codes[] = $language->getLocale()->getLanguageCode();
        }

        return $codes === [] ? [null] : array_values(array_unique($codes));
    }

    /**
     * SPEC-v3-caching-chunking.md Phase B: when $contentChanged is false, the
     * extracted content is byte-identical to what's already indexed (a metadata-only
     * re-save, or a queue-driven full reindex that found nothing different) — the
     * embedding-provider call is skipped and the previously stored vector is carried
     * forward instead, so a reindex doesn't re-embed unchanged documents.
     */
    private function addEmbeddingToDocument(Document $document, bool $contentChanged, SolrClientInterface $connection): void
    {
        $fields = $document->getFields();
        $content = $fields['content_t'] ?? '';
        $content = is_string($content) ? trim($content) : '';

        if ($content === '') {
            return;
        }

        if (!$contentChanged) {
            $existingVector = $this->fetchStoredVector($connection, $fields['id'] ?? null);

            if ($existingVector !== null) {
                $document->setField(SchemaManager::VECTOR_FIELD_NAME, $existingVector);

                return;
            }

            // No previously stored vector to carry forward (e.g. this document predates
            // the vector field, or was never successfully embedded) — fall through and
            // embed normally rather than leaving the re-indexed document without one.
        }

        $embeddingProvider = $this->providerRegistry->getActiveEmbeddingProvider();
        $content = $this->embeddingTextTruncator->truncate($content, $embeddingProvider->getMaxInputTokens());

        try {
            $vector = $embeddingProvider->embed($content);

            if ($vector !== []) {
                $document->setField(SchemaManager::VECTOR_FIELD_NAME, $vector);
            }
        } catch (\Throwable $e) {
            // Transient embedding failures must not break indexing — but the failure
            // must not vanish silently either, or a persistently misconfigured/down
            // provider produces vector-less documents forever with nothing in the logs
            // to explain why.
            $this->logger?->warning(
                'Embedding request failed while indexing document "{id}"; it will be stored without a vector field: {message}',
                ['id' => $document->getFields()['id'] ?? 'unknown', 'message' => $e->getMessage(), 'exception' => $e],
            );
        }
    }

    /**
     * Looks up the vector currently stored for $id, so an unchanged-content reindex
     * (SPEC-v3-caching-chunking.md Phase B) can carry it forward instead of leaving the
     * re-written document without one — Solr's addDocuments() replaces the whole
     * document, so simply not setting the field would silently drop the existing vector.
     *
     * @return list<float>|null
     */
    private function fetchStoredVector(SolrClientInterface $connection, mixed $id): ?array
    {
        if (!is_string($id) || $id === '') {
            return null;
        }

        try {
            $response = $connection->search((new SearchQuery())->setQuery('id:' . $id)->setRows(1));
        } catch (\Throwable) {
            return null;
        }

        $vector = $response->getDocuments()[0][SchemaManager::VECTOR_FIELD_NAME] ?? null;

        return is_array($vector) && $vector !== [] ? $vector : null;
    }
}
