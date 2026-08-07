<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Scheduler;

use Maispace\MaiSearch\Domain\Service\IndexQueueProcessor;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

class IndexQueueProcessingTask extends AbstractTask
{
    public function execute(): bool
    {
        $processor = GeneralUtility::makeInstance(IndexQueueProcessor::class);
        $processor->processBatch();

        return true;
    }
}
