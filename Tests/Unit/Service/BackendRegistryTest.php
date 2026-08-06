<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Service\BackendRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class BackendRegistryTest extends TestCase
{
    private function createMockBackend(string $identifier): SearchBackendInterface
    {
        $backend = $this->createMock(SearchBackendInterface::class);
        $backend->method('getIdentifier')->willReturn($identifier);

        return $backend;
    }

    private function createRegistry(array $settings): BackendRegistry
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn($settings);

        return new BackendRegistry($extensionConfiguration);
    }

    #[Test]
    public function getActiveReturnsBackendMatchingConfiguredIdentifier(): void
    {
        $registry = $this->createRegistry(['activeBackend' => 'meilisearch']);
        $solr = $this->createMockBackend('solr');
        $meilisearch = $this->createMockBackend('meilisearch');

        $registry->addBackend($solr);
        $registry->addBackend($meilisearch);

        self::assertSame($meilisearch, $registry->getActive());
    }

    #[Test]
    public function getActiveFallsBackToSolrWhenConfiguredBackendIsNotRegistered(): void
    {
        $registry = $this->createRegistry(['activeBackend' => 'meilisearch']);
        $solr = $this->createMockBackend('solr');

        $registry->addBackend($solr);

        self::assertSame($solr, $registry->getActive());
    }

    #[Test]
    public function getActiveDefaultsToSolrWhenNoActiveBackendConfigured(): void
    {
        $registry = $this->createRegistry([]);
        $solr = $this->createMockBackend('solr');

        $registry->addBackend($solr);

        self::assertSame($solr, $registry->getActive());
    }

    #[Test]
    public function getActiveThrowsWhenNoBackendIsRegisteredAtAll(): void
    {
        $registry = $this->createRegistry(['activeBackend' => 'solr']);

        $this->expectException(\RuntimeException::class);

        $registry->getActive();
    }

    #[Test]
    public function getReturnsNullForUnknownIdentifier(): void
    {
        $registry = $this->createRegistry([]);

        self::assertNull($registry->get('unknown'));
    }

    #[Test]
    public function getAllReturnsAllRegisteredBackends(): void
    {
        $registry = $this->createRegistry([]);
        $solr = $this->createMockBackend('solr');
        $indexedSearch = $this->createMockBackend('indexed_search');

        $registry->addBackend($solr);
        $registry->addBackend($indexedSearch);

        self::assertCount(2, $registry->getAll());
    }

    #[Test]
    public function getActiveFallsBackToSolrWhenExtensionConfigurationThrows(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new \RuntimeException('not configured'));

        $registry = new BackendRegistry($extensionConfiguration);
        $solr = $this->createMockBackend('solr');
        $registry->addBackend($solr);

        self::assertSame($solr, $registry->getActive());
    }
}
