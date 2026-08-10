<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory as MeilisearchConnectionFactory;
use Maispace\MaiSearch\Backend\Meilisearch\IndexNameResolver;
use Maispace\MaiSearch\Backend\Meilisearch\MeilisearchClientInterface;
use Maispace\MaiSearch\Backend\Meilisearch\VectorStoreProvisioner;
use Maispace\MaiSearch\Domain\Service\EmbeddingProviderInterface;
use Maispace\MaiSearch\Domain\Service\ProvisioningService;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Service\BackendRegistry;
use Maispace\MaiSearch\Service\ProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final class ProvisioningServiceTest extends TestCase
{
    private BackendRegistry $backendRegistry;
    private SchemaManager $schemaManager;
    private MeilisearchConnectionFactory $meilisearchConnectionFactory;
    private IndexNameResolver $indexNameResolver;
    private SiteFinder $siteFinder;
    private VectorStoreProvisioner $vectorStoreProvisioner;
    private ProviderRegistry $providerRegistry;
    private ProvisioningService $service;

    protected function setUp(): void
    {
        $this->backendRegistry = $this->createMock(BackendRegistry::class);
        $this->schemaManager = $this->createMock(SchemaManager::class);
        $this->meilisearchConnectionFactory = $this->createMock(MeilisearchConnectionFactory::class);
        $this->indexNameResolver = new IndexNameResolver();
        $this->siteFinder = $this->createMock(SiteFinder::class);
        $this->vectorStoreProvisioner = $this->createMock(VectorStoreProvisioner::class);

        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('getDimensions')->willReturn(1024);
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->providerRegistry->method('getActiveEmbeddingProvider')->willReturn($embeddingProvider);

        $this->service = new ProvisioningService(
            $this->backendRegistry,
            $this->schemaManager,
            $this->meilisearchConnectionFactory,
            $this->indexNameResolver,
            $this->siteFinder,
            $this->vectorStoreProvisioner,
            $this->providerRegistry,
        );
    }

    private function activeBackend(string $identifier): SearchBackendInterface
    {
        $backend = $this->createMock(SearchBackendInterface::class);
        $backend->method('getIdentifier')->willReturn($identifier);
        $this->backendRegistry->method('getActive')->willReturn($backend);

        return $backend;
    }

    #[Test]
    public function provisionCreatesSolrCoresAndEnsuresVectorFieldWhenSolrIsActive(): void
    {
        $this->activeBackend('solr');

        $this->schemaManager->expects(self::once())->method('ensureCoresExist')->willReturn(['core_de']);
        $this->schemaManager->expects(self::once())->method('ensureVectorFieldExistsInAllCores');

        $result = $this->service->provision();

        self::assertSame('solr', $result->backend);
        self::assertSame(['core_de'], $result->provisioned);
        self::assertNull($result->message);
    }

    #[Test]
    public function provisionEnsuresMeilisearchIndexPerSiteLanguageWhenMeilisearchIsActive(): void
    {
        $this->activeBackend('meilisearch');

        $site = $this->createMock(Site::class);
        $de = $this->createMock(SiteLanguage::class);
        $de->method('getLocale')->willReturn(new Locale('de'));
        $en = $this->createMock(SiteLanguage::class);
        $en->method('getLocale')->willReturn(new Locale('en'));
        $site->method('getAllLanguages')->willReturn([$de, $en]);

        $this->siteFinder->method('getAllSites')->willReturn(['main' => $site]);

        $client = $this->createMock(MeilisearchClientInterface::class);
        $client->expects(self::exactly(4))->method('ensureIndex');
        $client->expects(self::exactly(2))->method('configureEmbedder')->with(self::anything(), 'default', 1024);
        $this->meilisearchConnectionFactory->method('getClient')->willReturn($client);

        $this->vectorStoreProvisioner->expects(self::once())->method('ensureVectorStoreEnabled');

        $result = $this->service->provision();

        self::assertSame('meilisearch', $result->backend);
        self::assertSame(
            ['mai_search_de', 'mai_search_chunks_de', 'mai_search_en', 'mai_search_chunks_en'],
            $result->provisioned,
        );
    }

    #[Test]
    public function provisionFallsBackToDefaultIndexWhenNoSitesConfigured(): void
    {
        $this->activeBackend('meilisearch');
        $this->siteFinder->method('getAllSites')->willReturn([]);

        $client = $this->createMock(MeilisearchClientInterface::class);
        $client->expects(self::exactly(2))->method('ensureIndex')->with(self::logicalOr('mai_search', 'mai_search_chunks'), self::anything());
        $this->meilisearchConnectionFactory->method('getClient')->willReturn($client);

        $result = $this->service->provision();

        self::assertSame(['mai_search', 'mai_search_chunks'], $result->provisioned);
    }

    #[Test]
    public function provisionReturnsMessageForBackendsWithNothingToProvision(): void
    {
        $this->activeBackend('indexed_search');

        $result = $this->service->provision();

        self::assertSame('indexed_search', $result->backend);
        self::assertSame([], $result->provisioned);
        self::assertNotNull($result->message);
    }
}
