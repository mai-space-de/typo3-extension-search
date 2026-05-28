<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Solr;

use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use Solarium\Core\Client\Endpoint;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

class ConnectionFactory implements SingletonInterface
{
    private const DEFAULT_HOST = 'localhost';
    private const DEFAULT_PORT = 8983;
    private const DEFAULT_PATH = '/';
    private const DEFAULT_CORE = 'core_en';
    private const DEFAULT_SCHEME = 'http';

    private array $settings;

    public function __construct(
        private readonly ConfigurationManagerInterface $configurationManager,
    ) {
        $this->settings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'maisearch',
        );
    }

    public function getConnection(?SiteLanguage $language = null): SolrConnection
    {
        $solrSettings = $this->settings['solr'] ?? [];

        $core = $solrSettings['core'] ?? self::DEFAULT_CORE;

        if ($language !== null) {
            $languageCode = $language->getLocale()->getLanguageCode();
            $core = $solrSettings['coreMapping'][$languageCode] ?? $core;
        }

        return $this->buildConnection($core, $solrSettings);
    }

    /**
     * Resolves a Solr connection via a language code (e.g. 'de', 'en') rather than a SiteLanguage object.
     * Useful for scheduler tasks and CLI commands where no frontend request exists.
     */
    public function getConnectionForLanguageCode(string $languageCode): SolrConnection
    {
        $solrSettings = $this->settings['solr'] ?? [];
        $core = $solrSettings['coreMapping'][$languageCode] ?? ($solrSettings['core'] ?? self::DEFAULT_CORE);

        return $this->buildConnection($core, $solrSettings);
    }

    /**
     * @return array<string, string> language code → core name (e.g. 'de' → 'core_de')
     */
    public function getCoreMapping(): array
    {
        $solrSettings = $this->settings['solr'] ?? [];

        return $solrSettings['coreMapping'] ?? [];
    }

    /**
     * @param array<string, mixed> $solrSettings
     */
    protected function buildConnection(string $core, array $solrSettings): SolrConnection
    {
        $host = $solrSettings['host'] ?? self::DEFAULT_HOST;
        $port = (int) ($solrSettings['port'] ?? self::DEFAULT_PORT);
        $path = $solrSettings['path'] ?? self::DEFAULT_PATH;
        $scheme = $solrSettings['scheme'] ?? self::DEFAULT_SCHEME;

        $readEndpoint = new Endpoint([
            'host' => $host,
            'port' => $port,
            'path' => $path,
            'core' => $core,
            'scheme' => $scheme,
        ]);

        $writeEndpoint = new Endpoint([
            'host' => $host,
            'port' => $port,
            'path' => $path,
            'core' => $core,
            'scheme' => $scheme,
        ]);

        return new SolrConnection($readEndpoint, $writeEndpoint);
    }
}
