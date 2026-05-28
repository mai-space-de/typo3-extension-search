<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Scheduler;

use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

class FullReindexTask extends AbstractTask
{
    public function execute(): bool
    {
        $indexManagementService = GeneralUtility::makeInstance(IndexManagementService::class);
        $indexManagementService->reindexAll();

        return true;
    }
}
