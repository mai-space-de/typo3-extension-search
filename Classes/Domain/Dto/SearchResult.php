<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Dto;

readonly class SearchResult
{
    /**
     * @param string[]|null $rootline Breadcrumb trail — page titles from root to the result's parent page
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $snippet,
        public string $url,
        public string $icon,
        public ?\DateTime $date,
        public float $score,
        public ?array $rootline = null,
    ) {}
}
