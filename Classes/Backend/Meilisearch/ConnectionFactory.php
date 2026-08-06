<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Meilisearch;

use Meilisearch\Client;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ConnectionFactory implements SingletonInterface
{
    private const string DEFAULT_HOST = 'http://localhost:7700';

    private readonly string $host;
    private readonly string $apiKey;

    public function __construct(?ExtensionConfiguration $extensionConfiguration = null)
    {
        $extensionConfiguration ??= GeneralUtility::makeInstance(ExtensionConfiguration::class);

        try {
            $settings = $extensionConfiguration->get('mai_search');
        } catch (\Exception) {
            $settings = [];
        }

        $meilisearchSettings = $settings['meilisearch'] ?? [];

        $this->host = $this->resolveEnvString('MEILISEARCH_HOST') ?? (string) ($meilisearchSettings['host'] ?? self::DEFAULT_HOST);
        $this->apiKey = $this->resolveEnvString('MEILISEARCH_API_KEY') ?? (string) ($meilisearchSettings['apiKey'] ?? '');
    }

    public function getClient(): MeilisearchClientInterface
    {
        return new MeilisearchClient(new Client($this->host, $this->apiKey !== '' ? $this->apiKey : null));
    }

    private function resolveEnvString(string $environmentVariable): ?string
    {
        $value = getenv($environmentVariable);

        return $value === false || $value === '' ? null : $value;
    }
}
