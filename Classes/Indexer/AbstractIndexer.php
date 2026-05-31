<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Indexer;

use ApacheSolrForTypo3\Solr\System\Solr\Document\Document;
use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

abstract class AbstractIndexer implements SearchIndexerInterface
{
    protected ConnectionFactory $connectionFactory;

    protected PersistenceManager $persistenceManager;

    public function injectConnectionFactory(ConnectionFactory $connectionFactory): void
    {
        $this->connectionFactory = $connectionFactory;
    }

    public function injectPersistenceManager(PersistenceManager $persistenceManager): void
    {
        $this->persistenceManager = $persistenceManager;
    }

    /**
     * @param string[] $rootline Breadcrumb titles from site root to the record's parent page
     */
    protected function createDocument(
        string $type,
        int $uid,
        string $title,
        string $content,
        string $url,
        \DateTimeInterface $crdate,
        float $boost,
        array $rootline = [],
    ): Document {
        $document = GeneralUtility::makeInstance(Document::class);
        $document->setField('id', $type . '-' . $uid);
        $document->setField('type_s', $type);
        $document->setField('title_s', $title);
        $document->setField('title_t', $title);
        $document->setField('content_t', $content);
        $document->setField('url_s', $url);
        $document->setField('uid_i', $uid);
        $document->setField('crdate_dt', $crdate->format('Y-m-d\TH:i:s\Z'));
        $document->setField('boost_i', (int) $boost);
        $document->setField('rootline_s', implode(' | ', $rootline));

        return $document;
    }

    protected function sendDocument(Document $document, ?string $languageCode = null): void
    {
        $indexManagementService = GeneralUtility::makeInstance(IndexManagementService::class);
        $indexManagementService->addDocumentForLanguageCode($document, $languageCode);
    }

    public function getBoost(string $type): float
    {
        return 1.0;
    }

    abstract protected function buildContent(object $record): string;

    abstract protected function buildUrl(object $record): string;

    abstract protected function getRecordsForIndexing(IndexingContext $context): iterable;
}
