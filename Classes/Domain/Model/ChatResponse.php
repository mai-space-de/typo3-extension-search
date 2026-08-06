<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

final readonly class ChatResponse
{
    public function __construct(
        public string $content,
    ) {}
}
