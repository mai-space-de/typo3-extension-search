<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

/**
 * Splits HTML content into overlapping text chunks suitable for embedding.
 *
 * Strips HTML tags, normalises whitespace, then divides the clean text into
 * chunks of up to $chunkSize characters with $overlap characters of overlap
 * between consecutive chunks. Chunk boundaries are aligned to word breaks
 * (spaces) when possible to avoid mid-word splits.
 *
 * This is the first step in the RAG pipeline: raw HTML → clean chunks →
 * embeddings → vector search.
 */
final class ContentChunkerService
{
    /**
     * @param string $content Raw HTML content to chunk
     * @param int<1, max> $chunkSize Maximum number of characters per chunk
     * @param int<0, max> $overlap Number of overlapping characters between consecutive chunks
     * @return list<string> Clean text chunks, never empty strings
     */
    public function chunk(string $content, int $chunkSize = 500, int $overlap = 50): array
    {
        $text = $this->normalise($content);

        if ($text === '') {
            return [];
        }

        $overlap = min($overlap, $chunkSize - 1);
        $stride = $chunkSize - $overlap;
        $length = mb_strlen($text);

        if ($length <= $chunkSize) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;

        while ($offset < $length) {
            $end = min($offset + $chunkSize, $length);
            $chunk = mb_substr($text, $offset, $end - $offset);

            if ($end < $length) {
                $chunk = $this->alignToWordBoundary($chunk);
            }

            $chunk = trim($chunk);

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            $offset += $stride;
        }

        return $chunks;
    }

    private function normalise(string $content): string
    {
        $text = strip_tags($content);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private function alignToWordBoundary(string $chunk): string
    {
        $lastSpace = mb_strrpos($chunk, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            return mb_substr($chunk, 0, $lastSpace);
        }

        return $chunk;
    }
}
