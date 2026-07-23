<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Solr;

use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SolrClientInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class ConnectionFactoryTest extends TestCase
{
    private function createFactory(array $solrSettings = []): ConnectionFactory
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration
            ->method('get')
            ->with('mai_search')
            ->willReturn($solrSettings);

        $factory = $this->getMockBuilder(ConnectionFactory::class)
            ->setConstructorArgs([$extensionConfiguration])
            ->onlyMethods(['buildConnection'])
            ->getMock();

        $factory
            ->method('buildConnection')
            ->willReturnCallback(function (string $core): SolrClientInterface {
                $client = $this->createMock(SolrClientInterface::class);
                $client->method('getCore')->willReturn($core);
                $client->method('getEndpoint')->willReturn(
                    new \Solarium\Core\Client\Endpoint(['key' => 'mai_search', 'core' => $core]),
                );

                return $client;
            });

        return $factory;
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

        self::assertSame([
            'en' => 'core_en',
            'de' => 'core_de',
            'uk' => 'core_uk',
            'ar' => 'core_ar',
        ], $factory->getCoreMapping());
    }

    #[Test]
    public function getCoreMappingReturnsDefaultMappingWhenNotConfigured(): void
    {
        $factory = $this->createFactory([]);

        self::assertSame([
            'de' => 'core_de',
            'en' => 'core_en',
            'uk' => 'core_uk',
            'ar' => 'core_ar',
        ], $factory->getCoreMapping());
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

        self::assertSame('core_de', $connection->getCore());
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

        self::assertSame('core_default', $connection->getCore());
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

        self::assertSame('core_de', $connection->getCore());
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

        self::assertSame('core_en', $connection->getCore());
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

        self::assertSame('core_en', $connection->getCore());
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
            self::assertSame('mai_search', $settings['key']);
        } finally {
            putenv('TYPO3_SOLR_HOST');
        }
    }

    #[Test]
    public function buildConnectionNormalizesLegacySolrPathPrefix(): void
    {
        $factory = $this->createEndpointSettingsFactory(['host' => 'localhost', 'path' => '/solr/']);
        $settings = $this->invokeBuildEndpointSettings($factory, 'core_de', ['host' => 'localhost', 'path' => '/solr/']);

        self::assertSame('', $settings['path']);

        $endpoint = new \Solarium\Core\Client\Endpoint($settings);
        self::assertSame('http://localhost:8983/solr/core_de/', $endpoint->getCoreBaseUri());
    }

    /**
     * @param array<string, mixed> $solrSettings
     *
     * @return array{key: string, host: string, port: int, path: string, core: string, scheme: string}
     */
    private function invokeBuildEndpointSettings(ConnectionFactory $factory, string $core, array $solrSettings): array
    {
        $method = new \ReflectionMethod(ConnectionFactory::class, 'buildEndpointSettings');

        /** @var array{key: string, host: string, port: int, path: string, core: string, scheme: string} $settings */
        $settings = $method->invoke($factory, $core, $solrSettings);

        return $settings;
    }

    private function createEndpointSettingsFactory(array $solrSettings = []): ConnectionFactory
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration
            ->method('get')
            ->with('mai_search')
            ->willReturn($solrSettings);

        return new ConnectionFactory($extensionConfiguration);
    }
}
