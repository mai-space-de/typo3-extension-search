<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

final readonly class ChatRequest
{
    /**
     * @param ChatMessage[] $messages
     */
    public function __construct(
        public array $messages,
        public ?string $model = null,
        public float $temperature = 0.2,
    ) {}
}
