<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Maispace\MaiSearch\Service\SuggestRegistry;
use Maispace\MaiSearch\Suggest\SuggestInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class SuggestRegistryTest extends TestCase
{
    private function createMockSuggest(string $identifier): SuggestInterface
    {
        $suggest = $this->createMock(SuggestInterface::class);
        $suggest->method('getIdentifier')->willReturn($identifier);

        return $suggest;
    }

    private function createRegistry(array $settings): SuggestRegistry
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn($settings);

        return new SuggestRegistry($extensionConfiguration);
    }

    #[Test]
    public function getActiveReturnsSuggestMatchingConfiguredBackend(): void
    {
        $registry = $this->createRegistry(['activeBackend' => 'meilisearch']);
        $solr = $this->createMockSuggest('solr');
        $meilisearch = $this->createMockSuggest('meilisearch');

        $registry->addSuggest($solr);
        $registry->addSuggest($meilisearch);

        self::assertSame($meilisearch, $registry->getActive());
    }

    #[Test]
    public function getActiveFallsBackToSolrWhenConfiguredBackendHasNoSuggest(): void
    {
        $registry = $this->createRegistry(['activeBackend' => 'meilisearch']);
        $solr = $this->createMockSuggest('solr');

        $registry->addSuggest($solr);

        self::assertSame($solr, $registry->getActive());
    }

    #[Test]
    public function getActiveDefaultsToSolrWhenNoActiveBackendConfigured(): void
    {
        $registry = $this->createRegistry([]);
        $solr = $this->createMockSuggest('solr');

        $registry->addSuggest($solr);

        self::assertSame($solr, $registry->getActive());
    }

    #[Test]
    public function getActiveReturnsNullWhenNothingIsRegistered(): void
    {
        $registry = $this->createRegistry(['activeBackend' => 'solr']);

        self::assertNull($registry->getActive());
    }

    #[Test]
    public function getActiveFallsBackToSolrWhenExtensionConfigurationThrows(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new \RuntimeException('not configured'));

        $registry = new SuggestRegistry($extensionConfiguration);
        $solr = $this->createMockSuggest('solr');
        $registry->addSuggest($solr);

        self::assertSame($solr, $registry->getActive());
    }
}
