<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

/**
 * Backend-agnostic indexing payload for a single content chunk
 * (SPEC-v3-caching-chunking.md Phase D). One parent SearchDocumentInput
 * produces zero or more of these via ContentChunkerService — each chunk is
 * indexed as its own document (id "{parentType}-{parentUid}-chunk-{chunkIndex}")
 * so it can carry its own embedding vector, independent of the parent's own
 * (now retired, see SPEC-v3.md Decision 3) document-level vector.
 *
 * Parent title/url/type travel with every chunk rather than being looked up at
 * query time — ChunkSearchBackendInterface::searchChunks() returns plain
 * SearchResult objects built directly from the stored chunk document, with no
 * second lookup against the parent document.
 */
readonly class ChunkDocumentInput
{
    public function __construct(
        public string $parentType,
        public int $parentUid,
        public string $parentTitle,
        public string $parentUrl,
        public int $chunkIndex,
        public string $content,
        public ?string $languageCode = null,
    ) {}
}
