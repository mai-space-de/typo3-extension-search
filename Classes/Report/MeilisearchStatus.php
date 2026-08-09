<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Report;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class MeilisearchStatus implements StatusProviderInterface
{
    public function getLabel(): string
    {
        return 'Meilisearch';
    }

    public function getStatus(): array
    {
        $statuses = [];

        try {
            $connectionFactory = GeneralUtility::makeInstance(ConnectionFactory::class);
            $client = $connectionFactory->getClient();

            $health = $client->health();

            $statuses[] = new Status(
                'Meilisearch Health',
                (string) ($health['status'] ?? 'available'),
                sprintf('Status: %s', $health['status'] ?? 'available'),
                ContextualFeedbackSeverity::OK,
            );

            $stats = $client->stats();
            $indexCount = count($stats['indexes'] ?? []);
            $statuses[] = new Status(
                'Meilisearch Indexes',
                (string) $indexCount,
                sprintf('%d index(es), %s database size', $indexCount, $this->formatBytes((int) ($stats['databaseSize'] ?? 0))),
                ContextualFeedbackSeverity::OK,
            );
        } catch (\Throwable $e) {
            $statuses[] = new Status(
                'Meilisearch Connection',
                'Unreachable',
                sprintf('Meilisearch unreachable: %s', $e->getMessage()),
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return $statuses;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === end($units)) {
                return round($value, 1) . ' ' . $unit;
            }

            $value /= 1024;
        }

        return $bytes . ' B';
    }
}
