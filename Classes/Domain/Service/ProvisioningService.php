<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory as MeilisearchConnectionFactory;
use Maispace\MaiSearch\Backend\Meilisearch\IndexNameResolver as MeilisearchIndexNameResolver;
use Maispace\MaiSearch\Backend\Meilisearch\MeilisearchBackend;
use Maispace\MaiSearch\Backend\Meilisearch\VectorStoreProvisioner;
use Maispace\MaiSearch\Domain\Dto\ProvisioningResult;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Service\BackendRegistry;
use Maispace\MaiSearch\Service\ProviderRegistry;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Creates whatever infrastructure the active search backend needs — Solr
 * cores or Meilisearch indexes — so switching `activeBackend` or setting up a
 * new environment doesn't require manually provisioning the server first.
 * Shared by `mai-search:provision` and the backend module's "Provision" button.
 */
class ProvisioningService
{
    private const string TYPE_FILTER_ATTRIBUTE = 'type';

    /**
     * Filterable attributes the chunk index needs (SPEC-v3-caching-chunking.md
     * Phase E) so removeChunks() can delete-by-filter.
     */
    private const array CHUNK_FILTERABLE_ATTRIBUTES = ['parentType', 'parentUid'];

    public function __construct(
        private readonly BackendRegistry $backendRegistry,
        private readonly SchemaManager $schemaManager,
        private readonly MeilisearchConnectionFactory $meilisearchConnectionFactory,
        private readonly MeilisearchIndexNameResolver $meilisearchIndexNameResolver,
        private readonly SiteFinder $siteFinder,
        private readonly VectorStoreProvisioner $vectorStoreProvisioner,
        private readonly ProviderRegistry $providerRegistry,
    ) {}

    public function provision(): ProvisioningResult
    {
        $backend = $this->backendRegistry->getActive();

        return match ($backend->getIdentifier()) {
            'solr' => $this->provisionSolr(),
            'meilisearch' => $this->provisionMeilisearch(),
            default => new ProvisioningResult(
                $backend->getIdentifier(),
                [],
                sprintf('The "%s" backend has no infrastructure to provision.', $backend->getIdentifier()),
            ),
        };
    }

    private function provisionSolr(): ProvisioningResult
    {
        $createdCores = $this->schemaManager->ensureCoresExist();
        $this->schemaManager->ensureVectorFieldExistsInAllCores();

        return new ProvisioningResult('solr', $createdCores);
    }

    private function provisionMeilisearch(): ProvisioningResult
    {
        $client = $this->meilisearchConnectionFactory->getClient();
        $this->vectorStoreProvisioner->ensureVectorStoreEnabled();
        $dimensions = $this->providerRegistry->getActiveEmbeddingProvider()->getDimensions();

        $ensured = [];
        foreach ($this->resolveSiteLanguageCodes() as $languageCode) {
            $indexUid = $this->meilisearchIndexNameResolver->forLanguage($languageCode);
            $client->ensureIndex($indexUid, [self::TYPE_FILTER_ATTRIBUTE]);
            $ensured[] = $indexUid;

            // Separate index, never given an embedder on the main one above — see
            // MeilisearchBackend's class docblock for why they can't be shared.
            $chunkIndexUid = $this->meilisearchIndexNameResolver->forChunks($languageCode);
            $client->ensureIndex($chunkIndexUid, self::CHUNK_FILTERABLE_ATTRIBUTES);
            $client->configureEmbedder($chunkIndexUid, MeilisearchBackend::EMBEDDER_NAME, $dimensions);
            $ensured[] = $chunkIndexUid;
        }

        return new ProvisioningResult('meilisearch', array_values(array_unique($ensured)));
    }

    /**
     * @return list<string|null>
     */
    private function resolveSiteLanguageCodes(): array
    {
        $codes = [];

        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($site->getAllLanguages() as $language) {
                $codes[] = $language->getLocale()->getLanguageCode();
            }
        }

        return $codes === [] ? [null] : array_values(array_unique($codes));
    }
}
