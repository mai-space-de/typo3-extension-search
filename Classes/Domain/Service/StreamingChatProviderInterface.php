<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Model\ChatRequest;

/**
 * Optional capability on top of ChatProviderInterface for providers that can
 * stream tokens as they are generated (OpenAI SSE, Ollama NDJSON, ...).
 * SearchSynthesisService checks for this interface and falls back to a single
 * complete() call when a provider doesn't implement it.
 */
interface StreamingChatProviderInterface extends ChatProviderInterface
{
    /**
     * @return \Generator<int, string>
     */
    public function stream(ChatRequest $request): \Generator;
}
