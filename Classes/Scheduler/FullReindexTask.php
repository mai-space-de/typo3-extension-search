<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Scheduler;

use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * SPEC-v2 Phase 3: repopulates queue instead of direct indexing.
 */
class FullReindexTask extends AbstractTask
{
    public function execute(): bool
    {
        $indexManagementService = GeneralUtility::makeInstance(IndexManagementService::class);
        $indexQueueRepository = GeneralUtility::makeInstance(IndexQueueRepository::class);

        $indexManagementService->repopulateQueue($indexQueueRepository);

        return true;
    }
}
