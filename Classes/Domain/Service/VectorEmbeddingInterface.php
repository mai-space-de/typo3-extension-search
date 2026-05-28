<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

/**
 * Minimal contract for text embedding used during search indexing.
 *
 * This interface exists within mai_search to avoid a hard compile-time
 * dependency on mai_translate. When the mai_translate extension is loaded,
 * its EmbeddingServiceFactory creates an adapter that wraps the OpenAI-based
 * embedding service. Without mai_translate, vector indexing is silently skipped.
 *
 * @see \Maispace\MaiTranslate\Service\EmbeddingServiceInterface
 */
interface VectorEmbeddingInterface
{
    /**
     * @param string $text Input text to embed
     * @return list<float> Embedding vector
     */
    public function embedText(string $text): array;

    /**
     * @return int Maximum number of input tokens supported
     */
    public function getMaxInputTokens(): int;
}
