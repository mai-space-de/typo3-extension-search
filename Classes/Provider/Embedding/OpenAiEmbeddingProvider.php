<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Provider\Embedding;

use TYPO3\CMS\Core\Http\RequestFactory;

final class OpenAiEmbeddingProvider extends AbstractEmbeddingProvider
{
    private const string API_URL = 'https://api.openai.com/v1/embeddings';
    private const int MAX_INPUT_TOKENS = 8191;

    public function __construct(
        RequestFactory $requestFactory,
        private readonly string $apiKey,
        private readonly string $model = 'text-embedding-3-small',
        private readonly int $dimensions = 1536,
    ) {
        parent::__construct($requestFactory);
    }

    public function getIdentifier(): string
    {
        return 'openai';
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0] ?? [];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === [] || $this->apiKey === '') {
            return [];
        }

        $response = $this->requestFactory->request(self::API_URL, 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['model' => $this->model, 'input' => $texts], JSON_THROW_ON_ERROR),
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('OpenAI embeddings API error (HTTP %d)', $response->getStatusCode()));
        }

        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        return array_map(
            static fn(array $item): array => $item['embedding'],
            $data['data'] ?? [],
        );
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function getMaxInputTokens(): int
    {
        return self::MAX_INPUT_TOKENS;
    }
}
