<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Dto;

/**
 * Holds the result of a RAG answer synthesis call.
 *
 * Contains the AI-generated answer text along with the list of source
 * references that were used as context. When synthesis is skipped (e.g.
 * RAG disabled, empty context, or missing API key), both answer and
 * sources will be empty.
 *
 * @see SearchSynthesisService
 */
readonly class SynthesisResult
{
    /**
     * @param SourceReference[] $sources
     */
    public function __construct(
        public string $answer,
        public array $sources,
    ) {}

    /**
     * @param SourceReference[] $sources
     */
    public static function create(string $answer, array $sources): self
    {
        return new self($answer, $sources);
    }

    /**
     * Return an empty result indicating synthesis was skipped.
     */
    public static function empty(): self
    {
        return new self('', []);
    }
}
