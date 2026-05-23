<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use ApacheSolrForTypo3\Solr\System\Solr\Document\Document;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use TYPO3\CMS\Core\SingletonInterface;

class IndexManagementService implements SingletonInterface
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
    ) {}

    public function addDocument(Document $document): void
    {
        $connection = $this->connectionFactory->getConnection();
        $connection->getWriteService()->addDocuments([$document]);
        $connection->getWriteService()->commit(false, false);
    }

    public function deleteRecord(string $type, int $uid): void
    {
        $id = $type . '-' . $uid;
        $connection = $this->connectionFactory->getConnection();
        $connection->getWriteService()->deleteByQuery('id:' . $id);
        $connection->getWriteService()->commit(false, false);
    }

    public function deleteByType(string $type): void
    {
        $connection = $this->connectionFactory->getConnection();
        $connection->getWriteService()->deleteByType($type, false);
        $connection->getWriteService()->commit(false, false);
    }

    public function clearIndex(): void
    {
        $connection = $this->connectionFactory->getConnection();
        $connection->getWriteService()->deleteByQuery('*:*');
        $connection->getWriteService()->commit(false, false);
    }
}
