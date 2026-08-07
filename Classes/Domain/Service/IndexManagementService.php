<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\Document;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Domain\Solr\SearchQuery;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use Maispace\MaiSearch\Service\ProviderRegistry;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class IndexManagementService implements SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const float TOKENS_PER_CHAR = 0.25;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly ProviderRegistry $providerRegistry,
        private readonly ?IndexerRegistry $indexerRegistry = null,
        private readonly ?SiteFinder $siteFinder = null,
    ) {}

    public function addDocument(Document $document, ?SiteLanguage $language = null): void
    {
        $this->addEmbeddingToDocument($document);

        $connection = $this->connectionFactory->getConnection($language);
        $connection->addDocuments([$document]);
        $connection->commit(false, false);
    }

    /**
     * @param string|null $languageCode ISO 639-1 code for indexers working with
     *                                   IndexingContext::$languageCode strings
     */
    public function addDocumentForLanguageCode(Document $document, ?string $languageCode = null): void
    {
        $this->addEmbeddingToDocument($document);

        if ($languageCode !== null) {
            $connection = $this->connectionFactory->getConnectionForLanguageCode($languageCode);
        } else {
            $connection = $this->connectionFactory->getConnection();
        }

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
    }

    /**
     * Clear every configured language core.
     */
    public function clearAllCores(): void
    {
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
     * Query Solr for index statistics across all configured cores.
     *
     * Returns an array with per-core stats containing total document count
     * and per-type breakdowns via faceting on the `type_s` field.
     *
     * @return array{
     *     cores: array<string, array{core: string, totalDocuments: int, types: array<string, int>}>,
     *     totalDocuments: int,
     * }
     */
    public function getIndexStats(): array
    {
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

    private function addEmbeddingToDocument(Document $document): void
    {
        $fields = $document->getFields();
        $content = $fields['content_t'] ?? '';
        $content = is_string($content) ? trim($content) : '';

        if ($content === '') {
            return;
        }

        $embeddingProvider = $this->providerRegistry->getActiveEmbeddingProvider();
        $maxTokens = $embeddingProvider->getMaxInputTokens();
        $estimatedTokens = (int) ceil(mb_strlen($content) * self::TOKENS_PER_CHAR);

        if ($estimatedTokens > $maxTokens) {
            $maxChars = (int) floor($maxTokens / self::TOKENS_PER_CHAR);
            $content = mb_substr($content, 0, $maxChars);
        }

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
}
