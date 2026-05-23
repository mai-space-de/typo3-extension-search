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
