<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Report;

use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SearchResponse;
use Maispace\MaiSearch\Domain\Solr\SolrClientInterface;
use Maispace\MaiSearch\Report\SolrStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class SolrStatusTest extends TestCase
{
    #[Test]
    public function getLabelReturnsApacheSolr(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);

        self::assertSame('Apache Solr', (new SolrStatus($connectionFactory))->getLabel());
    }

    #[Test]
    public function getStatusChecksCoreEnWhenNoCoreMappingConfigured(): void
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client->method('search')->willReturn(new SearchResponse([], 5));

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getCoreMapping')->willReturn([]);
        $connectionFactory
            ->expects(self::once())
            ->method('getConnectionForLanguageCode')
            ->with('en')
            ->willReturn($client);

        $statuses = (new SolrStatus($connectionFactory))->getStatus();

        self::assertCount(1, $statuses);
        self::assertSame(ContextualFeedbackSeverity::OK, $statuses[0]->getSeverity());
        self::assertSame('5', $statuses[0]->getValue());
    }

    #[Test]
    public function getStatusChecksEveryConfiguredCore(): void
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client->method('search')->willReturn(new SearchResponse([], 1));

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getCoreMapping')->willReturn(['de' => 'core_de', 'en' => 'core_en']);
        $connectionFactory->method('getConnectionForLanguageCode')->willReturn($client);

        $statuses = (new SolrStatus($connectionFactory))->getStatus();

        self::assertCount(2, $statuses);
    }

    #[Test]
    public function getStatusReportsErrorForUnreachableCore(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getCoreMapping')->willReturn([]);
        $connectionFactory
            ->method('getConnectionForLanguageCode')
            ->willThrowException(new \RuntimeException('connection refused'));

        $statuses = (new SolrStatus($connectionFactory))->getStatus();

        self::assertCount(1, $statuses);
        self::assertSame(ContextualFeedbackSeverity::ERROR, $statuses[0]->getSeverity());
    }

    #[Test]
    public function getStatusReportsErrorWhenGetCoreMappingThrows(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getCoreMapping')->willThrowException(new \RuntimeException('misconfigured'));

        $statuses = (new SolrStatus($connectionFactory))->getStatus();

        self::assertCount(1, $statuses);
        self::assertSame(ContextualFeedbackSeverity::ERROR, $statuses[0]->getSeverity());
        self::assertSame('Solr Connection', $statuses[0]->getTitle());
    }
}
