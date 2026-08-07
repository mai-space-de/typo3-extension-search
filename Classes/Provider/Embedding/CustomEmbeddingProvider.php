<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Provider\Embedding;

use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Generic REST-template embedding provider for any embedding API — configure a
 * request body template and a dot-notation path into the response.
 */
final class CustomEmbeddingProvider extends AbstractEmbeddingProvider
{
    private const int DEFAULT_MAX_INPUT_TOKENS = 8191;

    public function __construct(
        RequestFactory $requestFactory,
        private readonly string $url,
        private readonly string $apiKey = '',
        private readonly int $dimensions = 0,
        private readonly string $requestTemplate = '{"input": "{{text}}"}',
        private readonly string $responsePath = 'data.0.embedding',
        private readonly int $maxInputTokens = self::DEFAULT_MAX_INPUT_TOKENS,
    ) {
        parent::__construct($requestFactory);
    }

    public function getIdentifier(): string
    {
        return 'custom';
    }

    public function embed(string $text): array
    {
        if ($this->url === '') {
            $this->logger?->debug('Custom embedding provider is not configured (missing endpoint URL)');

            return [];
        }

        $body = str_replace('{{text}}', addslashes($text), $this->requestTemplate);

        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $response = $this->requestFactory->request($this->url, 'POST', ['headers' => $headers, 'body' => $body]);

        $this->assertSuccessful($response);

        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        $embedding = $this->extractByPath($data, $this->responsePath);

        if (!is_array($embedding)) {
            $this->logger?->warning(
                'Custom embedding provider response did not contain an array at path "{path}"',
                ['path' => $this->responsePath],
            );

            return [];
        }

        return $embedding;
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function getMaxInputTokens(): int
    {
        return $this->maxInputTokens;
    }
}
