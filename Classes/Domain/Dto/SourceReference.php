<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Dto;

/**
 * Represents a single source reference used in a RAG synthesis answer.
 *
 * Each source corresponds to one of the top-ranked search results that was
 * included as context for the AI-generated answer.
 */
readonly class SourceReference
{
    public function __construct(
        public string $title,
        public string $url,
        public string $type,
        public float $score,
    ) {}
}
