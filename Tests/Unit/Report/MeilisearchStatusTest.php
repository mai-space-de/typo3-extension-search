<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Report;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory;
use Maispace\MaiSearch\Backend\Meilisearch\MeilisearchClientInterface;
use Maispace\MaiSearch\Report\MeilisearchStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class MeilisearchStatusTest extends TestCase
{
    #[Test]
    public function getLabelReturnsMeilisearch(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);

        self::assertSame('Meilisearch', (new MeilisearchStatus($connectionFactory))->getLabel());
    }

    #[Test]
    public function getStatusReportsHealthAndIndexCountWhenReachable(): void
    {
        $client = $this->createMock(MeilisearchClientInterface::class);
        $client->method('health')->willReturn(['status' => 'available']);
        $client->method('stats')->willReturn([
            'databaseSize' => 2048,
            'indexes' => ['mai_search' => [], 'mai_search_de' => []],
        ]);

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getClient')->willReturn($client);

        $statuses = (new MeilisearchStatus($connectionFactory))->getStatus();

        self::assertCount(2, $statuses);
        self::assertSame(ContextualFeedbackSeverity::OK, $statuses[0]->getSeverity());
        self::assertSame('available', $statuses[0]->getValue());
        self::assertSame(ContextualFeedbackSeverity::OK, $statuses[1]->getSeverity());
        self::assertSame('2', $statuses[1]->getValue());
    }

    #[Test]
    public function getStatusReportsErrorWhenUnreachable(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getClient')->willThrowException(new \RuntimeException('connection refused'));

        $statuses = (new MeilisearchStatus($connectionFactory))->getStatus();

        self::assertCount(1, $statuses);
        self::assertSame(ContextualFeedbackSeverity::ERROR, $statuses[0]->getSeverity());
        self::assertSame('Meilisearch Connection', $statuses[0]->getTitle());
    }

    #[Test]
    public function getStatusFormatsLargeDatabaseSizesWithUnits(): void
    {
        $client = $this->createMock(MeilisearchClientInterface::class);
        $client->method('health')->willReturn(['status' => 'available']);
        $client->method('stats')->willReturn([
            'databaseSize' => 5 * 1024 * 1024,
            'indexes' => [],
        ]);

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getClient')->willReturn($client);

        $statuses = (new MeilisearchStatus($connectionFactory))->getStatus();

        self::assertStringContainsString('5 MB', $statuses[1]->getMessage());
    }
}
