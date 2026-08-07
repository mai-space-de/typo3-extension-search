<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Provider\Chat;

use Maispace\MaiSearch\Domain\Model\ChatMessage;
use Maispace\MaiSearch\Domain\Model\ChatRequest;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OpenAiChatProvider extends AbstractChatProvider
{
    private const string API_URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        RequestFactory $requestFactory,
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4o-mini',
    ) {
        parent::__construct($requestFactory);
    }

    public function getIdentifier(): string
    {
        return 'openai';
    }

    public function stream(ChatRequest $request): \Generator
    {
        $response = $this->requestFactory->request(self::API_URL, 'POST', [
            'headers' => $this->headers(),
            'body' => json_encode($this->payload($request), JSON_THROW_ON_ERROR),
            'stream' => true,
        ]);

        $this->assertSuccessful($response);

        yield from $this->streamSse($response);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];
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
