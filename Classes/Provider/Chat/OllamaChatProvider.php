<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Provider\Chat;

use Maispace\MaiSearch\Domain\Model\ChatMessage;
use Maispace\MaiSearch\Domain\Model\ChatRequest;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OllamaChatProvider extends AbstractChatProvider
{
    public function __construct(
        RequestFactory $requestFactory,
        private readonly string $baseUrl = 'http://localhost:11434',
        private readonly string $model = 'llama3.1',
    ) {
        parent::__construct($requestFactory);
    }

    public function getIdentifier(): string
    {
        return 'ollama';
    }

    public function stream(ChatRequest $request): \Generator
    {
        $response = $this->requestFactory->request(
            rtrim($this->baseUrl, '/') . '/api/chat',
            'POST',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($this->payload($request), JSON_THROW_ON_ERROR),
                'stream' => true,
            ],
        );

        yield from $this->streamNdjson($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ChatRequest $request): array
    {
        return [
            'model' => $request->model ?? $this->model,
            'options' => ['temperature' => $request->temperature],
            'stream' => true,
            'messages' => array_map(
                static fn(ChatMessage $message): array => ['role' => $message->role, 'content' => $message->content],
                $request->messages,
            ),
        ];
    }
}
