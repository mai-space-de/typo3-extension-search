<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Widget\DataProvider;

use ApacheSolrForTypo3\Solr\System\Solr\ResponseAdapter;
use ApacheSolrForTypo3\Solr\System\Solr\Service\SolrAdminService;
use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Widget\DataProvider\SolrHealthDataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Solarium\Core\Client\Endpoint;

class SolrHealthDataProviderTest extends TestCase
{
    private ConnectionFactory $connectionFactory;
    private SolrConnection $solrConnection;
    private SolrAdminService $adminService;
    private Endpoint $endpoint;

    protected function setUp(): void
    {
        $this->endpoint = $this->createMock(Endpoint::class);
        $this->adminService = $this->createMock(SolrAdminService::class);
        $this->solrConnection = $this->createMock(SolrConnection::class);
        $this->connectionFactory = $this->createMock(ConnectionFactory::class);
    }

    private function createDataProvider(): SolrHealthDataProvider
    {
        return new SolrHealthDataProvider($this->connectionFactory);
    }

    #[Test]
    public function getHealthDataReturnsConnectedTrueWhenPingSucceeds(): void
    {
        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($this->solrConnection);

        $this->solrConnection
            ->method('getAdminService')
            ->willReturn($this->adminService);

        $this->adminService
            ->method('ping')
            ->willReturn(true);

        $this->adminService
            ->method('getPrimaryEndpoint')
            ->willReturn($this->endpoint);

        $this->endpoint
            ->method('getCore')
            ->willReturn('core_en');

        $this->adminService
            ->method('getSolrServerVersion')
            ->willReturn('9.8.0');

        $lukeData = new ResponseAdapter('{"index":{"numDocs":42}}', 200, 'OK');
        $this->adminService
            ->method('getLukeMetaData')
            ->willReturn($lukeData);

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
        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($this->solrConnection);

        $this->solrConnection
            ->method('getAdminService')
            ->willReturn($this->adminService);

        $this->adminService
            ->method('ping')
            ->willReturn(false);

        $this->adminService
            ->method('getPrimaryEndpoint')
            ->willReturn($this->endpoint);

        $this->endpoint
            ->method('getCore')
            ->willReturn('core_de');

        $healthData = $this->createDataProvider()->getHealthData();

        self::assertFalse($healthData['connected']);
        self::assertSame('core_de', $healthData['core']);
        self::assertSame(0, $healthData['numDocs']);
        self::assertSame('', $healthData['solrVersion']);
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
        self::assertSame(0, $healthData['numDocs']);
        self::assertSame('Connection refused', $healthData['error']);
    }

    #[Test]
    public function getHealthDataReturnsConnectedFalseWhenAdminServiceThrowsOnPing(): void
    {
        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($this->solrConnection);

        $this->solrConnection
            ->method('getAdminService')
            ->willThrowException(new \RuntimeException('Solr server unreachable'));

        $healthData = $this->createDataProvider()->getHealthData();

        self::assertFalse($healthData['connected']);
        self::assertSame('Solr server unreachable', $healthData['error']);
    }

    #[Test]
    public function getHealthDataHandlesZeroDocuments(): void
    {
        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($this->solrConnection);

        $this->solrConnection
            ->method('getAdminService')
            ->willReturn($this->adminService);

        $this->adminService
            ->method('ping')
            ->willReturn(true);

        $this->adminService
            ->method('getPrimaryEndpoint')
            ->willReturn($this->endpoint);

        $this->endpoint
            ->method('getCore')
            ->willReturn('core_en');

        $lukeData = new ResponseAdapter('{"index":{"numDocs":0}}', 200, 'OK');
        $this->adminService
            ->method('getLukeMetaData')
            ->willReturn($lukeData);

        $this->adminService
            ->method('getSolrServerVersion')
            ->willReturn('9.8.0');

        $healthData = $this->createDataProvider()->getHealthData();

        self::assertTrue($healthData['connected']);
        self::assertSame(0, $healthData['numDocs']);
    }
}
