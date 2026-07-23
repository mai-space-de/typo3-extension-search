<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Widget\DataProvider;

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
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'core' => '',
                'numDocs' => 0,
                'solrVersion' => '',
                'error' => $e->getMessage(),
            ];
        }

        $core = $connection->getCore();

        try {
            if (!$connection->ping()) {
                return [
                    'connected' => false,
                    'core' => $core,
                    'numDocs' => 0,
                    'solrVersion' => '',
                    'error' => 'Ping failed',
                ];
            }

            return [
                'connected' => true,
                'core' => $core,
                'numDocs' => $connection->getNumDocs(),
                'solrVersion' => $connection->getServerVersion(),
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'core' => $core,
                'numDocs' => 0,
                'solrVersion' => '',
                'error' => $e->getMessage(),
            ];
        }
    }
}
