<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

final readonly class ChatMessage
{
    /**
     * @param 'system'|'user'|'assistant' $role
     */
    public function __construct(
        public string $role,
        public string $content,
    ) {}
}
