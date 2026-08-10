<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Domain\Model\ChunkDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Service\ChunkSearchBackendInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Orchestrates chunk-level indexing for one parent document
 * (SPEC-v3-caching-chunking.md Phase D): chunks the content, diffs each chunk
 * against its stored hash, and only calls the backend (and re-embeds) for
 * chunks that actually changed. Trailing chunks left over from a shrunk
 * document are removed from both the backend and the local bookkeeping table.
 *
 * A no-op when the active backend doesn't implement ChunkSearchBackendInterface
 * (IndexedSearchBackend, or MeilisearchBackend before Phase E) — called
 * unconditionally from AbstractIndexer either way, same pattern as
 * ContentHashService/AnswerCacheService's optional setter-injection.
 *
 * Runs on every sendDocument() call regardless of
 * SearchDocumentInput::$contentChanged: that flag only says the whole
 * document's extracted text is byte-identical to last time, which is true only
 * after chunking has already run for it once. The first run after Phase D
 * ships, every existing document's chunk table is empty, so per-chunk hash
 * comparison — not the document-level flag — is what makes chunking
 * self-bootstrapping for already-indexed content.
 */
class ChunkIndexingService implements SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ContentChunkerService $chunker,
        private readonly ContentHashService $contentHashService,
        private readonly OrphanChunkCleanupService $chunkRepository,
        private readonly BackendRegistry $backendRegistry,
        private readonly RagConfiguration $ragConfiguration,
    ) {}

    public function indexChunks(SearchDocumentInput $document): void
    {
        $backend = $this->backendRegistry->getActive();

        if (!$backend instanceof ChunkSearchBackendInterface) {
            return;
        }

        $chunks = $this->chunker->chunk(
            $document->content,
            $this->ragConfiguration->getChunkSize(),
            $this->ragConfiguration->getChunkOverlap(),
        );

        foreach ($chunks as $index => $text) {
            $this->indexChunkIfChanged($backend, $document, $index, $text);
        }

        $this->removeOrphanChunks($backend, $document->type, $document->uid, count($chunks));
    }

    /**
     * Removes every chunk of a document that's being deleted entirely — the
     * counterpart to AbstractIndexer::removeDocument()'s ContentHashService
     * cleanup, called from the same place.
     */
    public function removeChunks(string $type, int $uid): void
    {
        $backend = $this->backendRegistry->getActive();

        if (!$backend instanceof ChunkSearchBackendInterface) {
            return;
        }

        $backend->removeChunks($type, $uid);
        $this->chunkRepository->removeAllChunks($type, $uid);
    }

    private function indexChunkIfChanged(
        ChunkSearchBackendInterface $backend,
        SearchDocumentInput $document,
        int $chunkIndex,
        string $text,
    ): void {
        $hash = $this->contentHashService->computeContentHash($text);

        if (!$this->chunkRepository->hasChunkChanged($document->type, $document->uid, $chunkIndex, $hash)) {
            return;
        }

        $languageCode = $document->additionalFields['languageCode'] ?? null;

        try {
            $backend->indexChunk(new ChunkDocumentInput(
                parentType: $document->type,
                parentUid: $document->uid,
                parentTitle: $document->title,
                parentUrl: $document->url,
                chunkIndex: $chunkIndex,
                content: $text,
                languageCode: is_string($languageCode) ? $languageCode : null,
            ));

            $this->chunkRepository->setChunkHash($document->type, $document->uid, $chunkIndex, $hash, $text);
        } catch (\Throwable $e) {
            // A chunk write failure (backend down, embedding provider unreachable)
            // must not fail the whole document's indexing — the same "don't fail
            // saves on Solr errors" precedent SearchBackendInterface::indexDocument()
            // itself already follows. The hash is deliberately not stored on
            // failure, so the next reindex retries this exact chunk instead of
            // silently treating a failed write as done.
            $this->logger?->warning(
                'Failed to index chunk {index} of {type}:{uid}: {message}',
                [
                    'index' => $chunkIndex,
                    'type' => $document->type,
                    'uid' => $document->uid,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ],
            );
        }
    }

    private function removeOrphanChunks(
        ChunkSearchBackendInterface $backend,
        string $type,
        int $uid,
        int $currentChunkCount,
    ): void {
        $orphanIndices = $this->chunkRepository->findOrphanChunkIndices($type, $uid, $currentChunkCount);

        foreach ($orphanIndices as $chunkIndex) {
            $backend->removeChunk($type, $uid, $chunkIndex);
        }

        if ($orphanIndices !== []) {
            $this->chunkRepository->cleanupOrphans($type, $uid, $currentChunkCount);
        }
    }
}
