<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use ApacheSolrForTypo3\Solr\System\Solr\Document\Document;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

class IndexManagementService implements SingletonInterface
{
    private const float TOKENS_PER_CHAR = 0.25;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly ?VectorEmbeddingInterface $vectorEmbeddingService = null,
    ) {}

    public function addDocument(Document $document, ?SiteLanguage $language = null): void
    {
        $this->addEmbeddingToDocument($document);

        $connection = $this->connectionFactory->getConnection($language);
        $connection->getWriteService()->addDocuments([$document]);
        $connection->getWriteService()->commit(false, false);
    }

    /**
     * @param string|null $languageCode ISO 639-1 code for indexers working with
     *                                   IndexingContext::$languageCode strings
     */
    public function addDocumentForLanguageCode(Document $document, ?string $languageCode = null): void
    {
        $this->addEmbeddingToDocument($document);

        if ($languageCode !== null) {
            $connection = $this->connectionFactory->getConnectionForLanguageCode($languageCode);
        } else {
            $connection = $this->connectionFactory->getConnection();
        }

        $connection->getWriteService()->addDocuments([$document]);
        $connection->getWriteService()->commit(false, false);
    }

    public function deleteRecord(string $type, int $uid, ?SiteLanguage $language = null): void
    {
        $id = $type . '-' . $uid;
        $connection = $this->connectionFactory->getConnection($language);
        $connection->getWriteService()->deleteByQuery('id:' . $id);
        $connection->getWriteService()->commit(false, false);
    }

    public function deleteByType(string $type, ?SiteLanguage $language = null): void
    {
        $connection = $this->connectionFactory->getConnection($language);
        $connection->getWriteService()->deleteByType($type, false);
        $connection->getWriteService()->commit(false, false);
    }

    public function clearIndex(?SiteLanguage $language = null): void
    {
        $connection = $this->connectionFactory->getConnection($language);
        $connection->getWriteService()->deleteByQuery('*:*');
        $connection->getWriteService()->commit(false, false);
    }

    private function addEmbeddingToDocument(Document $document): void
    {
        if ($this->vectorEmbeddingService === null) {
            return;
        }

        $fields = $document->getFields();
        $content = $fields['content_t'] ?? '';
        $content = is_string($content) ? trim($content) : '';

        if ($content === '') {
            return;
        }

        $maxTokens = $this->vectorEmbeddingService->getMaxInputTokens();
        $estimatedTokens = (int) ceil(mb_strlen($content) * self::TOKENS_PER_CHAR);

        if ($estimatedTokens > $maxTokens) {
            $maxChars = (int) floor($maxTokens / self::TOKENS_PER_CHAR);
            $content = mb_substr($content, 0, $maxChars);
        }

        try {
            $vector = $this->vectorEmbeddingService->embedText($content);

            if ($vector !== []) {
                $document->setField(SchemaManager::VECTOR_FIELD_NAME, $vector);
            }
        } catch (\Throwable) {
            // Transient embedding failures must not break indexing
        }
    }
}
