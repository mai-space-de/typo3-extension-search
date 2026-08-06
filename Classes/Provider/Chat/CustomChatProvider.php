<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Provider\Chat;

use Maispace\MaiSearch\Domain\Model\ChatMessage;
use Maispace\MaiSearch\Domain\Model\ChatRequest;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Targets any OpenAI-compatible chat completions endpoint (self-hosted gateways,
 * LiteLLM, vLLM, ...) — same SSE wire format as OpenAiChatProvider, configurable URL.
 */
final class CustomChatProvider extends AbstractChatProvider
{
    public function __construct(
        RequestFactory $requestFactory,
        private readonly string $url,
        private readonly string $apiKey = '',
        private readonly string $model = '',
    ) {
        parent::__construct($requestFactory);
    }

    public function getIdentifier(): string
    {
        return 'custom';
    }

    public function stream(ChatRequest $request): \Generator
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $response = $this->requestFactory->request($this->url, 'POST', [
            'headers' => $headers,
            'body' => json_encode($this->payload($request), JSON_THROW_ON_ERROR),
            'stream' => true,
        ]);

        yield from $this->streamSse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ChatRequest $request): array
    {
        return [
            'model' => $request->model ?? $this->model,
            'temperature' => $request->temperature,
            'stream' => true,
            'messages' => array_map(
                static fn(ChatMessage $message): array => ['role' => $message->role, 'content' => $message->content],
                $request->messages,
            ),
        ];
    }
}
