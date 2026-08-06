<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

/**
 * A provider capable of turning text into vector embeddings.
 *
 * Implemented by OpenAI, Ollama, and custom REST providers. Kept separate from
 * ChatProviderInterface (Interface Segregation) — a provider may implement one,
 * the other, or both.
 *
 * Ported from mai_meilisearch (SPEC-v2.md Phase 2): toMeilisearchEmbedderConfig()
 * was dropped here — it's a Meilisearch-specific concern that belongs on
 * MeilisearchBackend, not on a backend-agnostic provider interface also used by
 * SolrBackend. getMaxInputTokens() was added instead, replacing the removed
 * VectorEmbeddingInterface's method of the same name (IndexManagementService
 * uses it to truncate content before requesting an embedding).
 */
interface EmbeddingProviderInterface
{
    /**
     * Identifier this provider is registered under, e.g. 'openai', 'ollama', 'custom'.
     */
    public function getIdentifier(): string;

    /**
     * @return list<float>
     */
    public function embed(string $text): array;

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;

    public function getDimensions(): int;

    /**
     * Maximum number of input tokens this provider's model accepts.
     */
    public function getMaxInputTokens(): int;
}
