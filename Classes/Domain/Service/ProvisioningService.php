<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory as MeilisearchConnectionFactory;
use Maispace\MaiSearch\Backend\Meilisearch\IndexNameResolver as MeilisearchIndexNameResolver;
use Maispace\MaiSearch\Domain\Dto\ProvisioningResult;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Service\BackendRegistry;
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

    public function __construct(
        private readonly BackendRegistry $backendRegistry,
        private readonly SchemaManager $schemaManager,
        private readonly MeilisearchConnectionFactory $meilisearchConnectionFactory,
        private readonly MeilisearchIndexNameResolver $meilisearchIndexNameResolver,
        private readonly SiteFinder $siteFinder,
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

        $ensured = [];
        foreach ($this->resolveSiteLanguageCodes() as $languageCode) {
            $indexUid = $this->meilisearchIndexNameResolver->forLanguage($languageCode);
            $client->ensureIndex($indexUid, [self::TYPE_FILTER_ATTRIBUTE]);
            $ensured[] = $indexUid;
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
