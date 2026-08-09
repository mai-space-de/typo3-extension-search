<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Report;

use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class SolrStatus implements StatusProviderInterface
{
    public function getLabel(): string
    {
        return 'Apache Solr';
    }

    public function getStatus(): array
    {
        $statuses = [];

        try {
            $connectionFactory = GeneralUtility::makeInstance(ConnectionFactory::class);
            $coreMapping = $connectionFactory->getCoreMapping();

            if ($coreMapping === []) {
                $statuses[] = $this->checkCore('core_en', $connectionFactory);
            } else {
                foreach ($coreMapping as $languageCode => $core) {
                    $statuses[] = $this->checkCore($core, $connectionFactory);
                }
            }
        } catch (\Throwable $e) {
            $statuses[] = new Status(
                'Solr Connection',
                'Unreachable',
                sprintf('Failed to initialize Solr connection: %s', $e->getMessage()),
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return $statuses;
    }

    private function checkCore(string $core, ConnectionFactory $connectionFactory): Status
    {
        try {
            $connection = $connectionFactory->getConnectionForLanguageCode(
                $this->extractLanguageFromCore($core),
            );

            $query = new \Maispace\MaiSearch\Domain\Solr\SearchQuery();
            $query->setQuery('*:*')->setRows(0);

            $response = $connection->search($query);

            return new Status(
                sprintf('Core "%s"', $core),
                (string) $response->getNumFound(),
                sprintf('%d documents indexed', $response->getNumFound()),
                ContextualFeedbackSeverity::OK,
            );
        } catch (\Throwable $e) {
            return new Status(
                sprintf('Core "%s"', $core),
                'Unreachable',
                sprintf('Solr core unreachable: %s', $e->getMessage()),
                ContextualFeedbackSeverity::ERROR,
            );
        }
    }

    private function extractLanguageFromCore(string $core): string
    {
        if (preg_match('/^core_([a-z]{2,3})$/', $core, $matches) === 1) {
            return $matches[1];
        }

        return 'en';
    }
}
