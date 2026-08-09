<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Report;

use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class IndexedSearchStatus implements StatusProviderInterface
{
    public function getLabel(): string
    {
        return 'Indexed Search';
    }

    public function getStatus(): array
    {
        return [
            new Status(
                'Indexed Search Backend',
                'Active',
                'Using TYPO3 core indexed_search as search backend. Note: batch/eventual consistency, coarse relevance, page-level granularity only.',
                ContextualFeedbackSeverity::INFO,
            ),
        ];
    }
}
