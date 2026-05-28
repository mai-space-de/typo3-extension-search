<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Widget\DataProvider;

use ApacheSolrForTypo3\Solr\System\Solr\Service\SolrAdminService;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use TYPO3\CMS\Core\SingletonInterface;

class SolrHealthDataProvider implements SingletonInterface
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
    ) {}

    /**
     * @return array{connected: bool, core: string, numDocs: int, solrVersion: string, error: string}
     */
    public function getHealthData(): array
    {
        try {
            $connection = $this->connectionFactory->getConnection();
            $adminService = $connection->getAdminService();
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'core' => '',
                'numDocs' => 0,
                'solrVersion' => '',
                'error' => $e->getMessage(),
            ];
        }

        try {
            $endpoint = $adminService->getPrimaryEndpoint();
            $core = $endpoint->getCore();

            if (!$adminService->ping()) {
                return [
                    'connected' => false,
                    'core' => $core,
                    'numDocs' => 0,
                    'solrVersion' => '',
                    'error' => 'Ping failed',
                ];
            }

            $solrVersion = $this->getSolrVersion($adminService);
            $numDocs = $this->getNumDocs($adminService);

            return [
                'connected' => true,
                'core' => $core,
                'numDocs' => $numDocs,
                'solrVersion' => $solrVersion,
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'core' => $core ?? '',
                'numDocs' => 0,
                'solrVersion' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getSolrVersion(SolrAdminService $adminService): string
    {
        try {
            return $adminService->getSolrServerVersion();
        } catch (\Throwable) {
            return '';
        }
    }

    private function getNumDocs(SolrAdminService $adminService): int
    {
        try {
            $lukeData = $adminService->getLukeMetaData();
            if (isset($lukeData->index->numDocs)) {
                return (int) $lukeData->index->numDocs;
            }
        } catch (\Throwable) {
        }

        return 0;
    }
}
