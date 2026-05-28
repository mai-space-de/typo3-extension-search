<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Scheduler;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Service\IndexerRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

class FullReindexTask extends AbstractTask
{
    public function execute(): bool
    {
        $registry = GeneralUtility::makeInstance(IndexerRegistry::class);
        $connectionFactory = GeneralUtility::makeInstance(ConnectionFactory::class);

        $coreMapping = $connectionFactory->getCoreMapping();
        $indexers = $registry->getAll();

        // If no core mapping is configured, fall back to a single default-core reindex
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

            return true;
        }

        // Iterate over each configured language core and reindex all records
        foreach ($coreMapping as $languageCode => $core) {
            $context = GeneralUtility::makeInstance(
                IndexingContext::class,
                $core,
                100,
                0,
                $languageCode,
            );

            foreach ($indexers as $indexer) {
                $indexer->indexAll($context);
            }
        }

        return true;
    }
}
