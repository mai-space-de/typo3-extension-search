<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Provider\Embedding;

use Maispace\MaiSearch\Domain\Service\EmbeddingProviderInterface;
use Maispace\MaiSearch\Support\ExtractsResponsePath;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;

abstract class AbstractEmbeddingProvider implements EmbeddingProviderInterface, LoggerAwareInterface
{
    use ExtractsResponsePath;
    use LoggerAwareTrait;

    public function __construct(protected readonly RequestFactory $requestFactory) {}

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array
    {
        return array_map($this->embed(...), $texts);
    }

    /**
     * @throws \RuntimeException when the response status is not successful; logged before throwing
     *   so a caller that swallows the exception (e.g. IndexManagementService::addEmbeddingToDocument())
     *   still leaves a trace.
     */
    protected function assertSuccessful(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 300) {
            $this->logger?->error(
                '{provider} embeddings API returned HTTP {status}',
                ['provider' => $this->getIdentifier(), 'status' => $statusCode],
            );

            throw new \RuntimeException(sprintf('%s embeddings API error (HTTP %d)', $this->getIdentifier(), $statusCode));
        }
    }
}
