<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Model\ChatRequest;
use Maispace\MaiSearch\Domain\Model\ChatResponse;

interface ChatProviderInterface
{
    /**
     * Identifier this provider is registered under, e.g. 'openai', 'ollama', 'custom'.
     */
    public function getIdentifier(): string;

    public function complete(ChatRequest $request): ChatResponse;
}
