<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Report;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class MeilisearchStatus implements StatusProviderInterface
{
    public function getTitle(): string
    {
        return 'Meilisearch';
    }

    public function getStatus(): array
    {
        $statuses = [];

        try {
            $connectionFactory = GeneralUtility::makeInstance(ConnectionFactory::class);
            $client = $connectionFactory->getConnection();

            $health = $client->health();

            $statuses[] = new Status(
                'Meilisearch Health',
                Status::OK,
                sprintf('Status: %s', $health['status'] ?? 'available'),
            );

            $stats = $client->stats();
            $statuses[] = new Status(
                'Meilisearch Indexes',
                Status::OK,
                sprintf('%d index(es), %d total documents', $stats['numberOfIndexes'] ?? 0, $stats['databaseSize'] ?? 0),
            );
        } catch (\Throwable $e) {
            $statuses[] = new Status(
                'Meilisearch Connection',
                Status::ERROR,
                sprintf('Meilisearch unreachable: %s', $e->getMessage()),
            );
        }

        return $statuses;
    }
}
