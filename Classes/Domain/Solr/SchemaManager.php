<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Solr;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Applies the Solr DenseVectorField type and the `content_vector` field
 * to every configured Solr core via the Schema REST API.
 *
 * 1536 dimensions / cosine similarity / HNSW — matches OpenAI text-embedding-3-small.
 * Call ensureVectorFieldExists() once per core before indexing starts.
 */
final class SchemaManager implements SingletonInterface
{
    public const VECTOR_FIELD_NAME = 'content_vector';
    public const VECTOR_DIMENSIONS = 1536;
    public const VECTOR_FIELD_TYPE_NAME = 'knn_vector_1536';

    private const SCHEMA_API_PATH = '/solr/%s/schema';

    private ClientInterface $httpClient;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        ?ClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * @throws \RuntimeException When the Schema API returns an error for any core.
     */
    public function ensureVectorFieldExistsInAllCores(): void
    {
        $coreMapping = $this->connectionFactory->getCoreMapping();

        if ($coreMapping === []) {
            return;
        }

        foreach ($coreMapping as $core) {
            $this->ensureVectorFieldExists($core);
        }
    }

    /**
     * Idempotent: Solr 400 "already exists" responses are silently ignored.
     *
     * @throws \RuntimeException When the Schema API returns an unexpected error.
     */
    public function ensureVectorFieldExists(string $core): void
    {
        $baseUrl = $this->buildBaseUrl($core);

        $this->addFieldTypeIfMissing($baseUrl, $core);
        $this->addFieldIfMissing($baseUrl, $core);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFieldTypePayload(): array
    {
        return [
            'add-field-type' => [
                'name' => self::VECTOR_FIELD_TYPE_NAME,
                'class' => 'solr.DenseVectorField',
                'vectorDimension' => self::VECTOR_DIMENSIONS,
                'vectorEncoding' => 'float32',
                'similarityFunction' => 'cosine',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFieldPayload(): array
    {
        return [
            'add-field' => [
                'name' => self::VECTOR_FIELD_NAME,
                'type' => self::VECTOR_FIELD_TYPE_NAME,
                'indexed' => true,
                'stored' => true,
                'multiValued' => false,
            ],
        ];
    }

    private function addFieldTypeIfMissing(string $baseUrl, string $core): void
    {
        $this->postSchemaPayload($baseUrl, $core, $this->buildFieldTypePayload());
    }

    private function addFieldIfMissing(string $baseUrl, string $core): void
    {
        $this->postSchemaPayload($baseUrl, $core, $this->buildFieldPayload());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \RuntimeException
     */
    private function postSchemaPayload(string $baseUrl, string $core, array $payload): void
    {
        try {
            $response = $this->httpClient->request('POST', $baseUrl, [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json'],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 500) {
                throw new \RuntimeException(
                    sprintf(
                        'Solr Schema API returned HTTP %d for core "%s". Body: %s',
                        $statusCode,
                        $core,
                        (string) $response->getBody(),
                    ),
                    1748476800,
                );
            }
        } catch (GuzzleException $e) {
            // Solr returns HTTP 400 when a field type or field already exists — safe to ignore.
            if ($this->isAlreadyExistsError($e)) {
                return;
            }

            throw new \RuntimeException(
                sprintf('Solr Schema API request failed for core "%s": %s', $core, $e->getMessage()),
                1748476801,
                $e,
            );
        }
    }

    private function isAlreadyExistsError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        if ($e instanceof RequestException && $e->hasResponse()) {
            $message .= (string) $e->getResponse()->getBody();
        }

        return str_contains($message, 'already exists')
            || str_contains($message, 'already a field')
            || (str_contains($message, '400') && str_contains($message, 'already'));
    }

    private function buildBaseUrl(string $core): string
    {
        $connection = $this->connectionFactory->getConnectionForLanguageCode(
            $this->resolveLanguageCodeFromCore($core),
        );

        $endpoint = $connection->getEndpoint();

        return sprintf(
            '%s://%s:%d%s',
            $endpoint->getScheme(),
            $endpoint->getHost(),
            $endpoint->getPort(),
            sprintf(self::SCHEMA_API_PATH, $core),
        );
    }

    private function resolveLanguageCodeFromCore(string $core): string
    {
        if (preg_match('/^core_([a-z]{2,3})$/', $core, $matches) === 1) {
            return $matches[1];
        }

        return 'en';
    }
}
