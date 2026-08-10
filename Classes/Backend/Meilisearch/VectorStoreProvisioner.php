<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Meilisearch;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Enables Meilisearch's vector store experimental feature
 * (SPEC-v3-caching-chunking.md Phase E, Decision 5) so index settings accept
 * an `embedders` configuration at all. Vector search has been
 * experimental-flagged since Meilisearch v1.3; this project's DDEV
 * environment runs v1.9 (.ddev/docker-compose.meilisearch.yaml), where it
 * still requires this explicit opt-in via `PATCH /experimental-features`.
 *
 * No typed endpoint exists for this in `meilisearch/meilisearch-php` — raw
 * HTTP via Guzzle, the same pattern `Domain\Solr\SchemaManager` already uses
 * for Solr's Schema API rather than going through Solarium for
 * infrastructure-provisioning concerns.
 *
 * Idempotent and tolerant of failure: re-enabling an already-enabled feature,
 * or running against a Meilisearch version where the flag has since been
 * removed because the feature stabilised, must not break provisioning —
 * logged and swallowed, matching SchemaManager's own "already exists"
 * tolerance for Solr.
 */
class VectorStoreProvisioner implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const string EXPERIMENTAL_FEATURES_PATH = '/experimental-features';

    private ClientInterface $httpClient;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        ?ClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new Client();
    }

    public function ensureVectorStoreEnabled(): void
    {
        try {
            $this->httpClient->request(
                'PATCH',
                rtrim($this->connectionFactory->getHost(), '/') . self::EXPERIMENTAL_FEATURES_PATH,
                [
                    'json' => ['vectorStore' => true],
                    'headers' => $this->buildHeaders(),
                ],
            );
        } catch (GuzzleException $e) {
            $this->logger?->warning(
                'Could not enable the Meilisearch vectorStore experimental feature — '
                    . 'chunk vector search may not work until it is enabled manually: {message}',
                ['message' => $e->getMessage()],
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        $apiKey = $this->connectionFactory->getApiKey();

        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return $headers;
    }
}
