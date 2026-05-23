<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Dto;

readonly class SearchResult
{
    public function __construct(
        public string $type,
        public string $title,
        public string $snippet,
        public string $url,
        public string $icon,
        public ?\DateTime $date,
        public float $score,
    ) {}
}
