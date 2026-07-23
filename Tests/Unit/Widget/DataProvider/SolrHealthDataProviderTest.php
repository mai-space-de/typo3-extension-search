<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Widget\DataProvider;

use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SolrClientInterface;
use Maispace\MaiSearch\Widget\DataProvider\SolrHealthDataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SolrHealthDataProviderTest extends TestCase
{
    private ConnectionFactory $connectionFactory;
    private SolrClientInterface $solrClient;

    protected function setUp(): void
    {
        $this->solrClient = $this->createMock(SolrClientInterface::class);
        $this->connectionFactory = $this->createMock(ConnectionFactory::class);
    }

    private function createDataProvider(): SolrHealthDataProvider
    {
        return new SolrHealthDataProvider($this->connectionFactory);
    }

    #[Test]
    public function getHealthDataReturnsConnectedTrueWhenPingSucceeds(): void
    {
        $this->connectionFactory->method('getConnection')->willReturn($this->solrClient);
        $this->solrClient->method('getCore')->willReturn('core_en');
        $this->solrClient->method('ping')->willReturn(true);
        $this->solrClient->method('getNumDocs')->willReturn(42);
        $this->solrClient->method('getServerVersion')->willReturn('9.8.0');

        $healthData = $this->createDataProvider()->getHealthData();

        self::assertTrue($healthData['connected']);
        self::assertSame('core_en', $healthData['core']);
        self::assertSame(42, $healthData['numDocs']);
        self::assertSame('9.8.0', $healthData['solrVersion']);
        self::assertSame('', $healthData['error']);
    }

    #[Test]
    public function getHealthDataReturnsConnectedFalseWhenPingFails(): void
    {
        $this->connectionFactory->method('getConnection')->willReturn($this->solrClient);
        $this->solrClient->method('getCore')->willReturn('core_de');
        $this->solrClient->method('ping')->willReturn(false);

        $healthData = $this->createDataProvider()->getHealthData();

        self::assertFalse($healthData['connected']);
        self::assertSame('core_de', $healthData['core']);
        self::assertSame(0, $healthData['numDocs']);
        self::assertSame('Ping failed', $healthData['error']);
    }

    #[Test]
    public function getHealthDataReturnsConnectedFalseWhenConnectionFactoryThrows(): void
    {
        $this->connectionFactory
            ->method('getConnection')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $healthData = $this->createDataProvider()->getHealthData();

        self::assertFalse($healthData['connected']);
        self::assertSame('', $healthData['core']);
        self::assertSame('Connection refused', $healthData['error']);
    }

    #[Test]
    public function getHealthDataReturnsConnectedFalseWhenPingThrows(): void
    {
        $this->connectionFactory->method('getConnection')->willReturn($this->solrClient);
        $this->solrClient->method('getCore')->willReturn('core_en');
        $this->solrClient
            ->method('ping')
            ->willThrowException(new \RuntimeException('Solr server unreachable'));

        $healthData = $this->createDataProvider()->getHealthData();

        self::assertFalse($healthData['connected']);
        self::assertSame('Solr server unreachable', $healthData['error']);
    }

    #[Test]
    public function getHealthDataHandlesZeroDocuments(): void
    {
        $this->connectionFactory->method('getConnection')->willReturn($this->solrClient);
        $this->solrClient->method('getCore')->willReturn('core_en');
        $this->solrClient->method('ping')->willReturn(true);
        $this->solrClient->method('getNumDocs')->willReturn(0);
        $this->solrClient->method('getServerVersion')->willReturn('9.8.0');

        $healthData = $this->createDataProvider()->getHealthData();

        self::assertTrue($healthData['connected']);
        self::assertSame(0, $healthData['numDocs']);
    }
}
