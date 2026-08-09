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
 * 1024 dimensions / cosine similarity / HNSW — matches Ollama's bge-m3 embedding
 * model (switched from OpenAI text-embedding-3-small's 1536 dims; the active
 * embedding provider and this schema's vector dimension must always agree, or
 * Solr rejects the KNN query outright). Call ensureVectorFieldExists() once per
 * core before indexing starts.
 */
class SchemaManager implements SingletonInterface
{
    public const VECTOR_FIELD_NAME = 'content_vector';
    public const VECTOR_DIMENSIONS = 1024;
    public const VECTOR_FIELD_TYPE_NAME = 'knn_vector_1024';

    private const SCHEMA_API_PATH = '/solr/%s/schema';
    private const CORE_ADMIN_PATH = '/solr/admin/cores';
    private const DEFAULT_CONFIG_SET = '_default';
    private const DEFAULT_CORE = 'core_en';

    private ClientInterface $httpClient;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        ?ClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * Creates every core referenced by the configured language mapping (or the
     * single default core when unconfigured) via Solr's CoreAdminHandler, using
     * the `_default` configset every standard Solr install ships — no custom
     * configset upload needed. Mai_search's own `_s`/`_t`/`_i`/`_dt` field naming
     * convention is deliberately chosen to match `_default`'s built-in dynamic
     * field patterns, so no further schema definition is required.
     *
     * Idempotent: existing cores are detected via CoreAdminHandler STATUS and
     * left untouched.
     *
     * @return list<string> Core names that were newly created (empty if all already existed)
     *
     * @throws \RuntimeException When the CoreAdminHandler returns an unexpected error.
     */
    public function ensureCoresExist(): array
    {
        $coreMapping = $this->connectionFactory->getCoreMapping();
        $cores = $coreMapping === [] ? [self::DEFAULT_CORE] : array_values($coreMapping);

        $created = [];
        foreach (array_unique($cores) as $core) {
            if (!$this->coreExists($core)) {
                $this->createCore($core);
                $created[] = $core;
            }
        }

        return $created;
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

    private function coreExists(string $core): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->buildAdminUrl(), [
                'query' => ['action' => 'STATUS', 'core' => $core, 'wt' => 'json'],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            return is_array($body) && array_key_exists('status', $body) && is_array($body['status'])
                && array_key_exists($core, $body['status']);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                sprintf('Solr CoreAdminHandler STATUS request failed for core "%s": %s', $core, $e->getMessage()),
                1748900100,
                $e,
            );
        }
    }

    /**
     * @throws \RuntimeException When core creation fails for a reason other than the core already existing.
     */
    private function createCore(string $core): void
    {
        try {
            $response = $this->httpClient->request('GET', $this->buildAdminUrl(), [
                'query' => [
                    'action' => 'CREATE',
                    'name' => $core,
                    'configSet' => self::DEFAULT_CONFIG_SET,
                    'wt' => 'json',
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                throw new \RuntimeException(
                    sprintf(
                        'Solr CoreAdminHandler CREATE returned HTTP %d for core "%s". Body: %s',
                        $statusCode,
                        $core,
                        (string) $response->getBody(),
                    ),
                    1748900101,
                );
            }
        } catch (GuzzleException $e) {
            if ($this->isAlreadyExistsError($e)) {
                return;
            }

            throw new \RuntimeException(
                sprintf('Solr CoreAdminHandler CREATE request failed for core "%s": %s', $core, $e->getMessage()),
                1748900102,
                $e,
            );
        }
    }

    private function buildAdminUrl(): string
    {
        $endpoint = $this->connectionFactory->getConnection()->getEndpoint();

        return sprintf(
            '%s://%s:%d%s',
            $endpoint->getScheme(),
            $endpoint->getHost(),
            $endpoint->getPort(),
            self::CORE_ADMIN_PATH,
        );
    }
}
