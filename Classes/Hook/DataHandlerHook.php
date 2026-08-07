<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Hook;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook for incremental Solr index updates.
 *
 * Hooks:
 *   processDatamap_afterDatabaseOperations — re-index the record after save.
 *   processCmdmap_deleteAction             — remove the document from Solr on delete.
 *
 * Solr connectivity failures must never abort the DataHandler save/delete —
 * they are logged and surfaced as a backend flash message at most.
 */
class DataHandlerHook
{
    private readonly IndexerRegistry $indexerRegistry;

    private readonly SiteFinder $siteFinder;

    public function __construct(
        ?IndexerRegistry $indexerRegistry = null,
        private readonly ?FlashMessageService $flashMessageService = null,
        private readonly ?LoggerInterface $logger = null,
        ?SiteFinder $siteFinder = null,
    ) {
        $this->indexerRegistry = $indexerRegistry
            ?? GeneralUtility::makeInstance(IndexerRegistry::class);
        $this->siteFinder = $siteFinder
            ?? GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Called after a record was written to the database.
     *
     * @param string              $status   'new' or 'update'
     * @param string              $table    Table name
     * @param int|string          $id       Record UID (may be a "NEW…" string for fresh inserts)
     * @param array<string,mixed> $fieldArray Fields that were saved
     * @param DataHandler         $dataHandler
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        $indexer = $this->indexerRegistry->getIndexerForTable($table);
        if ($indexer === null) {
            return;
        }

        // Resolve the real UID for new records
        if ($status === 'new') {
            $uid = (int) ($dataHandler->substNEWwithIDs[$id] ?? 0);
        } else {
            $uid = (int) $id;
        }

        if ($uid <= 0) {
            return;
        }

        $record = $this->fetchRecord($table, $uid);
        if ($record === null) {
            return;
        }

        $languageUid = isset($record->sys_language_uid) ? (int) $record->sys_language_uid : 0;
        $languageCode = $this->resolveLanguageCode($languageUid);

        try {
            $indexer->indexRecord($record, new IndexingContext(
                core: '',
                languageCode: $languageCode,
                languageUid: $languageUid,
            ));
        } catch (\Throwable $exception) {
            $this->handleIndexingFailure(
                'index',
                $table,
                $uid,
                $exception,
            );
        }
    }

    /**
     * Called before a record is deleted.
     *
     * @param string      $table
     * @param int         $id
     * @param array<string,mixed> $recordToDelete
     * @param bool        $recordWasDeleted (unused – passed by reference in TYPO3 core)
     * @param DataHandler $dataHandler
     */
    public function processCmdmap_deleteAction(
        string $table,
        int $id,
        array $recordToDelete,
        bool &$recordWasDeleted,
        DataHandler $dataHandler,
    ): void {
        $indexer = $this->indexerRegistry->getIndexerForTable($table);
        if ($indexer === null) {
            return;
        }

        try {
            $indexer->removeRecord($id, $table);
        } catch (\Throwable $exception) {
            $this->handleIndexingFailure(
                'remove',
                $table,
                $id,
                $exception,
            );
        }
    }

    /**
     * Fetch a single record as \stdClass (without TYPO3 restriction overlay so that
     * hidden/deleted records are still re-indexed correctly from the DataHandler context).
     */
    private function fetchRecord(string $table, int $uid): ?\stdClass
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);

        // Remove default restrictions — DataHandler already applied access checks.
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
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

    /**
     * Resolve the ISO 639-1 code for a sys_language_uid via the site's configured languages,
     * so incremental re-index-on-save routes to the same core as a full reindex.
     */
    private function resolveLanguageCode(int $languageUid): ?string
    {
        try {
            $sites = $this->siteFinder->getAllSites();
        } catch (\Throwable) {
            return null;
        }

        $site = reset($sites);

        if ($site === false) {
            return null;
        }

        foreach ($site->getLanguages() as $language) {
            if ($language->getLanguageId() === $languageUid) {
                return $language->getLocale()->getLanguageCode();
            }
        }

        return null;
    }

    private function handleIndexingFailure(
        string $operation,
        string $table,
        int $uid,
        \Throwable $exception,
    ): void {
        $this->logger?->warning(
            'Solr {operation} failed for {table}:{uid}: {message}',
            [
                'operation' => $operation,
                'table' => $table,
                'uid' => $uid,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ],
        );

        $title = $this->translate('hook.indexingFailed.title');
        $message = sprintf(
            $this->translate('hook.indexingFailed.message'),
            $table,
            $uid,
            $exception->getMessage(),
        );

        $this->enqueueFlashMessage($message, $title);
    }

    private function enqueueFlashMessage(string $message, string $title): void
    {
        $flashMessageService = $this->flashMessageService
            ?? GeneralUtility::makeInstance(FlashMessageService::class);

        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $title,
            ContextualFeedbackSeverity::WARNING,
            true,
        );

        $flashMessageService->getMessageQueueByIdentifier()->enqueue($flashMessage);
    }

    private function translate(string $key): string
    {
        $label = 'LLL:EXT:mai_search/Resources/Private/Language/Default/locallang_backend.xlf:' . $key;

        if (isset($GLOBALS['LANG']) && is_object($GLOBALS['LANG']) && method_exists($GLOBALS['LANG'], 'sL')) {
            $translated = (string) $GLOBALS['LANG']->sL($label);
            if ($translated !== '' && $translated !== $label) {
                return $translated;
            }
        }

        return match ($key) {
            'hook.indexingFailed.title' => 'Search index update failed',
            'hook.indexingFailed.message' => 'The record was saved, but Solr indexing failed for %s:%d (%s).',
            default => $key,
        };
    }
}
