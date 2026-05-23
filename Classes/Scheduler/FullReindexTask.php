<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Scheduler;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Service\IndexerRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

class FullReindexTask extends AbstractTask
{
    public function execute(): bool
    {
        $registry = GeneralUtility::makeInstance(IndexerRegistry::class);

        $context = GeneralUtility::makeInstance(
            IndexingContext::class,
            'core_en',
            100,
            0,
        );

        foreach ($registry->getAll() as $indexer) {
            $indexer->indexAll($context);
        }

        return true;
    }
}
