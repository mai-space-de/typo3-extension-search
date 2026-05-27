<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Indexer;

use ApacheSolrForTypo3\Solr\System\Solr\Document\Document;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Service\SearchResultFormatterInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageIndexer extends AbstractIndexer implements SearchResultFormatterInterface
{
    private const DOKTYPE_STANDARD = 1;

    public function getType(): string
    {
        return 'page';
    }

    public function supports(string $table): bool
    {
        return $table === 'pages' || $table === 'tt_content';
    }

    public function getBoost(string $type): float
    {
        return 1.5;
    }

    public function indexAll(IndexingContext $context): void
    {
        foreach ($this->getRecordsForIndexing($context) as $record) {
            $this->indexRecord($record, $context);
        }
    }

    public function indexRecord(object $record, IndexingContext $context): void
    {
        if (!$record instanceof \stdClass) {
            return;
        }

        $pageContentText = $this->fetchPageContentText((int) $record->uid);

        $document = $this->createDocument(
            type: $this->getType(),
            uid: (int) $record->uid,
            title: (string) ($record->title ?? ''),
            content: $this->buildContent($record, $pageContentText),
            url: $this->buildUrl($record),
            crdate: $this->resolveDate($record),
            boost: $this->getBoost($this->getType()),
        );

        $this->sendDocument($document);
    }

    public function removeRecord(int $uid, string $table): void
    {
        if ($table !== 'pages' && $table !== 'tt_content') {
            return;
        }

        if ($table === 'tt_content') {
            $pageUid = $this->getPageUidForContent($uid);
            if ($pageUid > 0) {
                $record = $this->fetchPageRecord($pageUid);
                if ($record !== null) {
                    $context = new IndexingContext(core: 'core_de');
                    $this->indexRecord($record, $context);
                }
            }

            return;
        }

        $connection = $this->connectionFactory->getConnection();
        $connection->getWriteService()->deleteByQuery('id:' . $this->getType() . '-' . $uid);
        $connection->getWriteService()->commit(false, false);
    }

    protected function buildContent(object $record, string $pageContentText = ''): string
    {
        if (!$record instanceof \stdClass) {
            return '';
        }

        $parts = [];

        if (!empty($record->description)) {
            $parts[] = strip_tags((string) $record->description);
        }

        if ($pageContentText !== '') {
            $parts[] = $pageContentText;
        }

        return implode("\n", $parts);
    }

    protected function buildUrl(object $record): string
    {
        if (!$record instanceof \stdClass) {
            return '';
        }

        $uid = (int) $record->uid;

        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($uid);

            return (string) $site->getRouter()->generateUri($uid);
        } catch (\Exception) {
            return '';
        }
    }

    protected function getRecordsForIndexing(IndexingContext $context): iterable
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $rows = $queryBuilder
            ->select('uid', 'title', 'description', 'slug', 'crdate', 'tstamp', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(self::DOKTYPE_STANDARD, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('no_search', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults($context->batchSize)
            ->setFirstResult($context->offset)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static function (array $row): \stdClass {
            $obj = new \stdClass();
            foreach ($row as $key => $value) {
                $obj->$key = $value;
            }

            return $obj;
        }, $rows);
    }

    public function formatResult(array $solrDoc): SearchResult
    {
        return new SearchResult(
            type: $this->getType(),
            title: $solrDoc['title_s'] ?? '',
            snippet: $this->buildSnippet($solrDoc),
            url: $solrDoc['url_s'] ?? '',
            icon: $this->getIcon($this->getType()),
            date: $this->parseDate($solrDoc),
            score: (float) ($solrDoc['score'] ?? 0.0),
        );
    }

    public function getIcon(string $type): string
    {
        return 'apps-pagetree-page-default';
    }

    private function fetchPageContentText(int $pageUid): string
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $rows = $queryBuilder
            ->select('header', 'bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $parts = [];
        foreach ($rows as $row) {
            if (!empty($row['header'])) {
                $parts[] = strip_tags((string) $row['header']);
            }

            if (!empty($row['bodytext'])) {
                $parts[] = strip_tags((string) $row['bodytext']);
            }
        }

        return implode(' ', $parts);
    }

    private function fetchPageRecord(int $pageUid): ?\stdClass
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $row = $queryBuilder
            ->select('uid', 'title', 'description', 'slug', 'crdate', 'tstamp', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $obj = new \stdClass();
        foreach ($row as $key => $value) {
            $obj->$key = $value;
        }

        return $obj;
    }

    private function getPageUidForContent(int $contentUid): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $row = $queryBuilder
            ->select('pid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($contentUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return (int) ($row['pid'] ?? 0);
    }

    private function resolveDate(\stdClass $record): \DateTime
    {
        $timestamp = (int) ($record->tstamp ?? $record->crdate ?? 0);
        if ($timestamp > 0) {
            $dt = new \DateTime();
            $dt->setTimestamp($timestamp);

            return $dt;
        }

        return new \DateTime();
    }

    private function buildSnippet(array $solrDoc): string
    {
        $content = $solrDoc['content_t'] ?? '';

        return mb_substr(strip_tags($content), 0, 200);
    }

    private function parseDate(array $solrDoc): ?\DateTime
    {
        if (empty($solrDoc['crdate_dt'])) {
            return null;
        }

        try {
            return new \DateTime($solrDoc['crdate_dt']);
        } catch (\Exception) {
            return null;
        }
    }
}
