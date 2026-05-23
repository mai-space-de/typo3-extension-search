<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Indexer;

use ApacheSolrForTypo3\Solr\System\Solr\Document\Document;
use Maispace\MaiSearch\Domain\Model\IndexingContext;
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

    protected function createDocument(
        string $type,
        int $uid,
        string $title,
        string $content,
        string $url,
        \DateTime $crdate,
        float $boost,
    ): Document {
        $document = GeneralUtility::makeInstance(Document::class);
        $document->setField('id', $type . '-' . $uid);
        $document->setField('type_s', $type);
        $document->setField('title_s', $title);
        $document->setField('content_t', $content);
        $document->setField('url_s', $url);
        $document->setField('uid_i', $uid);
        $document->setField('crdate_dt', $crdate->format('Y-m-d\TH:i:s\Z'));
        $document->setField('boost_i', (int) $boost);

        return $document;
    }

    protected function sendDocument(Document $document): void
    {
        $connection = $this->connectionFactory->getConnection();
        $connection->getWriteService()->addDocuments([$document]);
    }

    public function getBoost(string $type): float
    {
        return 1.0;
    }

    abstract protected function buildContent(object $record): string;

    abstract protected function buildUrl(object $record): string;

    abstract protected function getRecordsForIndexing(IndexingContext $context): iterable;
}
