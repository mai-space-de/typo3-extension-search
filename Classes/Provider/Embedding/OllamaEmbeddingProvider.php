<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Provider\Embedding;

use TYPO3\CMS\Core\Http\RequestFactory;

final class OllamaEmbeddingProvider extends AbstractEmbeddingProvider
{
    private const int MAX_INPUT_TOKENS = 8192;

    public function __construct(
        RequestFactory $requestFactory,
        private readonly string $baseUrl = 'http://localhost:11434',
        private readonly string $model = 'nomic-embed-text',
        private readonly int $dimensions = 768,
    ) {
        parent::__construct($requestFactory);
    }

    public function getIdentifier(): string
    {
        return 'ollama';
    }

    public function embed(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $response = $this->requestFactory->request(
            $this->endpoint(),
            'POST',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['model' => $this->model, 'prompt' => $text], JSON_THROW_ON_ERROR),
            ],
        );

        $this->assertSuccessful($response);

        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        return $data['embedding'] ?? [];
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function getMaxInputTokens(): int
    {
        return self::MAX_INPUT_TOKENS;
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/') . '/api/embeddings';
    }
}
