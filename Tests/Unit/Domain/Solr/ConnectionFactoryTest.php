<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Solr;

use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

final class ConnectionFactoryTest extends TestCase
{
    private function createFactory(array $solrSettings = []): ConnectionFactory
    {
        $settings = ['solr' => $solrSettings];

        $configurationManager = $this->createMock(ConfigurationManagerInterface::class);
        $configurationManager
            ->method('getConfiguration')
            ->with(ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS, 'maisearch')
            ->willReturn($settings);

        $factory = $this->getMockBuilder(ConnectionFactory::class)
            ->setConstructorArgs([$configurationManager])
            ->onlyMethods(['buildConnection'])
            ->getMock();

        $factory
            ->method('buildConnection')
            ->willReturnCallback(function (string $core) {
                return $this->createMockConnectionWithCore($core);
            });

        return $factory;
    }

    private function createMockConnectionWithCore(string $core): SolrConnection
    {
        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getReadService')->willReturn(
            $this->createConfiguredMock(
                \ApacheSolrForTypo3\Solr\System\Solr\Service\SolrReadService::class,
                ['getPrimaryEndpoint' => $this->createEndpoint($core)],
            ),
        );
        $connection->method('getWriteService')->willReturn(
            $this->createConfiguredMock(
                \ApacheSolrForTypo3\Solr\System\Solr\Service\SolrWriteService::class,
                ['getPrimaryEndpoint' => $this->createEndpoint($core)],
            ),
        );

        return $connection;
    }

    private function createEndpoint(string $core): \Solarium\Core\Client\Endpoint
    {
        return new \Solarium\Core\Client\Endpoint(['core' => $core]);
    }

    #[Test]
    public function getCoreMappingReturnsConfiguredMapping(): void
    {
        $factory = $this->createFactory([
            'coreMapping' => [
                'en' => 'core_en',
                'de' => 'core_de',
                'uk' => 'core_uk',
                'ar' => 'core_ar',
            ],
        ]);

        $mapping = $factory->getCoreMapping();

        self::assertSame([
            'en' => 'core_en',
            'de' => 'core_de',
            'uk' => 'core_uk',
            'ar' => 'core_ar',
        ], $mapping);
    }

    #[Test]
    public function getCoreMappingReturnsEmptyArrayWhenNotConfigured(): void
    {
        $factory = $this->createFactory([]);

        $mapping = $factory->getCoreMapping();

        self::assertSame([], $mapping);
    }

    #[Test]
    public function getConnectionForLanguageCodeResolvesCoreFromMapping(): void
    {
        $factory = $this->createFactory([
            'coreMapping' => [
                'de' => 'core_de',
                'en' => 'core_en',
            ],
        ]);

        $connection = $factory->getConnectionForLanguageCode('de');

        $endpoint = $connection->getReadService()->getPrimaryEndpoint();
        self::assertSame('core_de', $endpoint->getCore());
    }

    #[Test]
    public function getConnectionForLanguageCodeFallsBackToDefaultCore(): void
    {
        $factory = $this->createFactory([
            'core' => 'core_default',
            'coreMapping' => [
                'de' => 'core_de',
            ],
        ]);

        $connection = $factory->getConnectionForLanguageCode('fr');

        $endpoint = $connection->getReadService()->getPrimaryEndpoint();
        self::assertSame('core_default', $endpoint->getCore());
    }

    #[Test]
    public function getConnectionUsesCoreMappingWhenLanguageProvided(): void
    {
        $factory = $this->createFactory([
            'core' => 'core_en',
            'coreMapping' => [
                'de' => 'core_de',
                'en' => 'core_en',
            ],
        ]);

        $language = $this->createMock(SiteLanguage::class);
        $locale = new \TYPO3\CMS\Core\Localization\Locale('de');
        $language->method('getLocale')->willReturn($locale);

        $connection = $factory->getConnection($language);

        $endpoint = $connection->getReadService()->getPrimaryEndpoint();
        self::assertSame('core_de', $endpoint->getCore());
    }

    #[Test]
    public function getConnectionUsesDefaultCoreWhenNoLanguage(): void
    {
        $factory = $this->createFactory([
            'core' => 'core_en',
            'coreMapping' => [
                'de' => 'core_de',
            ],
        ]);

        $connection = $factory->getConnection(null);

        $endpoint = $connection->getReadService()->getPrimaryEndpoint();
        self::assertSame('core_en', $endpoint->getCore());
    }

    #[Test]
    public function getConnectionFallsBackToDefaultCoreForUnmappedLanguage(): void
    {
        $factory = $this->createFactory([
            'core' => 'core_en',
            'coreMapping' => [
                'de' => 'core_de',
            ],
        ]);

        $language = $this->createMock(SiteLanguage::class);
        $locale = new \TYPO3\CMS\Core\Localization\Locale('fr');
        $language->method('getLocale')->willReturn($locale);

        $connection = $factory->getConnection($language);

        $endpoint = $connection->getReadService()->getPrimaryEndpoint();
        self::assertSame('core_en', $endpoint->getCore());
    }

    #[Test]
    public function buildConnectionUsesTypo3SolrHostEnvironmentVariable(): void
    {
        putenv('TYPO3_SOLR_HOST=solr');

        try {
            $factory = $this->createEndpointSettingsFactory(['host' => 'localhost', 'path' => '/']);
            $settings = $this->invokeBuildEndpointSettings($factory, 'core_de', ['host' => 'localhost', 'path' => '/']);

            self::assertSame('solr', $settings['host']);
            self::assertSame('core_de', $settings['core']);
        } finally {
            putenv('TYPO3_SOLR_HOST');
        }
    }

    #[Test]
    public function buildConnectionNormalizesLegacySolrPathPrefix(): void
    {
        $factory = $this->createEndpointSettingsFactory(['host' => 'localhost', 'path' => '/solr/']);
        $settings = $this->invokeBuildEndpointSettings($factory, 'core_de', ['host' => 'localhost', 'path' => '/solr/']);

        self::assertSame('/', $settings['path']);

        $endpoint = new \Solarium\Core\Client\Endpoint($settings);
        self::assertSame('http://localhost:8983/solr/core_de/', $endpoint->getCoreBaseUri());
    }

    /**
     * @param array<string, mixed> $solrSettings
     *
     * @return array{host: string, port: int, path: string, core: string, scheme: string}
     */
    private function invokeBuildEndpointSettings(ConnectionFactory $factory, string $core, array $solrSettings): array
    {
        $method = new \ReflectionMethod(ConnectionFactory::class, 'buildEndpointSettings');
        /** @var array{host: string, port: int, path: string, core: string, scheme: string} $settings */
        $settings = $method->invoke($factory, $core, $solrSettings);

        return $settings;
    }

    private function createEndpointSettingsFactory(array $solrSettings = []): ConnectionFactory
    {
        $settings = ['solr' => $solrSettings];

        $configurationManager = $this->createMock(ConfigurationManagerInterface::class);
        $configurationManager
            ->method('getConfiguration')
            ->with(ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS, 'maisearch')
            ->willReturn($settings);

        return new ConnectionFactory($configurationManager);
    }
}
