<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

/**
 * Shared truncation helper so text sent to an embedding provider never
 * exceeds its declared max input tokens. Extracted from
 * IndexManagementService::addEmbeddingToDocument() (originally Solr-only) so
 * MeilisearchBackend's chunk embedding (SPEC-v3-caching-chunking.md Phase E)
 * uses the identical estimate instead of a second, potentially drifting copy.
 *
 * TOKENS_PER_CHAR is a rough estimate (English-text average), not a real
 * tokenizer — good enough to stay safely under a provider's limit without
 * needing a per-provider tokenizer dependency.
 */
final class EmbeddingTextTruncator
{
    private const float TOKENS_PER_CHAR = 0.25;

    public function truncate(string $text, int $maxTokens): string
    {
        $estimatedTokens = (int) ceil(mb_strlen($text) * self::TOKENS_PER_CHAR);

        if ($estimatedTokens <= $maxTokens) {
            return $text;
        }

        $maxChars = (int) floor($maxTokens / self::TOKENS_PER_CHAR);

        return mb_substr($text, 0, $maxChars);
    }
}
