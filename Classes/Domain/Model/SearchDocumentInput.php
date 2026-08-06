<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

/**
 * Backend-agnostic indexing payload, mirroring the field set every indexer
 * already builds via AbstractIndexer::createDocument(). SearchBackendInterface
 * implementations translate this into their native write format (Solr Document,
 * Meilisearch document array, ...) — see SPEC-v2.md Phase 2.
 */
readonly class SearchDocumentInput
{
    /**
     * @param string[] $rootline Breadcrumb titles from site root to the record's parent page
     * @param array<string, mixed> $additionalFields Backend- or indexer-specific extra fields
     */
    public function __construct(
        public string $type,
        public int $uid,
        public string $title,
        public string $content,
        public string $url,
        public \DateTimeInterface $crdate,
        public float $boost,
        public array $rootline = [],
        public array $additionalFields = [],
    ) {}
}
