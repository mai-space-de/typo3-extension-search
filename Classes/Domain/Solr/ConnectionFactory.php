<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Solr;

use Solarium\Core\Client\Endpoint;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ConnectionFactory implements SingletonInterface
{
    private const DEFAULT_HOST = 'solr';
    private const DEFAULT_PORT = 8983;
    private const DEFAULT_PATH = '';
    private const DEFAULT_CORE = 'core_en';
    private const DEFAULT_SCHEME = 'http';
    private const ENDPOINT_KEY = 'mai_search';

    private array $settings;

    public function __construct(
        ?ExtensionConfiguration $extensionConfiguration = null,
    ) {
        $extensionConfiguration = $extensionConfiguration ?? GeneralUtility::makeInstance(ExtensionConfiguration::class);

        try {
            $typoScriptSettings = $extensionConfiguration->get('mai_search');
        } catch (\Exception) {
            $typoScriptSettings = [];
        }

        $this->settings = [
            'solr' => [
                'host' => $this->resolveEnvString('TYPO3_SOLR_HOST') ?? ($typoScriptSettings['host'] ?? self::DEFAULT_HOST),
                'port' => (int) ($this->resolveEnvString('TYPO3_SOLR_PORT') ?? (string) ($typoScriptSettings['port'] ?? self::DEFAULT_PORT)),
                'path' => $typoScriptSettings['path'] ?? self::DEFAULT_PATH,
                'core' => $typoScriptSettings['core'] ?? self::DEFAULT_CORE,
                'coreMapping' => $typoScriptSettings['coreMapping'] ?? [
                    'de' => 'core_de',
                    'en' => 'core_en',
                    'uk' => 'core_uk',
                    'ar' => 'core_ar',
                ],
                'scheme' => $typoScriptSettings['scheme'] ?? self::DEFAULT_SCHEME,
            ],
        ];
    }

    public function getConnection(?SiteLanguage $language = null): SolrClientInterface
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
    public function getConnectionForLanguageCode(string $languageCode): SolrClientInterface
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
    protected function buildConnection(string $core, array $solrSettings): SolrClientInterface
    {
        $endpointSettings = $this->buildEndpointSettings($core, $solrSettings);
        $endpoint = new Endpoint($endpointSettings);

        return new SolrClient($endpoint);
    }

    /**
     * @param array<string, mixed> $solrSettings
     *
     * @return array{key: string, host: string, port: int, path: string, core: string, scheme: string}
     */
    protected function buildEndpointSettings(string $core, array $solrSettings): array
    {
        return [
            'key' => self::ENDPOINT_KEY,
            'host' => $this->resolveEnvString('TYPO3_SOLR_HOST')
                ?? ($solrSettings['host'] ?? self::DEFAULT_HOST),
            'port' => (int) ($this->resolveEnvString('TYPO3_SOLR_PORT')
                ?? (string) ($solrSettings['port'] ?? self::DEFAULT_PORT)),
            'path' => $this->resolvePath($solrSettings),
            'core' => $core,
            'scheme' => $solrSettings['scheme'] ?? self::DEFAULT_SCHEME,
        ];
    }

    /**
     * Solarium adds its own "solr" context segment to the request URI.
     * The endpoint path must therefore not contain "/solr/" (see EXT:solr site config docs).
     *
     * @param array<string, mixed> $solrSettings
     */
    private function resolvePath(array $solrSettings): string
    {
        $envPath = $this->resolveEnvString('TYPO3_SOLR_PATH');
        if ($envPath !== null) {
            return $envPath;
        }

        $path = (string) ($solrSettings['path'] ?? self::DEFAULT_PATH);
        $normalized = rtrim($path, '/');

        if ($normalized === '' || $normalized === '/solr') {
            return self::DEFAULT_PATH;
        }

        return $path;
    }

    private function resolveEnvString(string $environmentVariable): ?string
    {
        $value = getenv($environmentVariable);

        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}
