<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Report;

use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class IndexedSearchStatus implements StatusProviderInterface
{
    public function getTitle(): string
    {
        return 'Indexed Search';
    }

    public function getStatus(): array
    {
        return [
            new Status(
                'Indexed Search Backend',
                Status::INFO,
                'Using TYPO3 core indexed_search as search backend. Note: batch/eventual consistency, coarse relevance, page-level granularity only.',
            ),
        ];
    }
}
