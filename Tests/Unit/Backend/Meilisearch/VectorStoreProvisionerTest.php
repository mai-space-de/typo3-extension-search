<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Backend\Meilisearch;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory;
use Maispace\MaiSearch\Backend\Meilisearch\VectorStoreProvisioner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class VectorStoreProvisionerTest extends TestCase
{
    private function createConnectionFactory(string $host, string $apiKey = ''): ConnectionFactory&MockObject
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getHost')->willReturn($host);
        $connectionFactory->method('getApiKey')->willReturn($apiKey);

        return $connectionFactory;
    }

    #[Test]
    public function ensureVectorStoreEnabledPatchesTheExperimentalFeaturesEndpoint(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'PATCH',
                'http://meilisearch:7700/experimental-features',
                self::callback(static function (array $options): bool {
                    return $options['json'] === ['vectorStore' => true];
                }),
            );

        $provisioner = new VectorStoreProvisioner($this->createConnectionFactory('http://meilisearch:7700'), $httpClient);
        $provisioner->ensureVectorStoreEnabled();
    }

    #[Test]
    public function ensureVectorStoreEnabledIncludesAuthorizationHeaderWhenApiKeyIsConfigured(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static fn(array $options): bool => ($options['headers']['Authorization'] ?? null) === 'Bearer secret'),
            );

        $provisioner = new VectorStoreProvisioner($this->createConnectionFactory('http://meilisearch:7700', 'secret'), $httpClient);
        $provisioner->ensureVectorStoreEnabled();
    }

    #[Test]
    public function ensureVectorStoreEnabledOmitsAuthorizationHeaderWithoutAnApiKey(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static fn(array $options): bool => !isset($options['headers']['Authorization'])),
            );

        $provisioner = new VectorStoreProvisioner($this->createConnectionFactory('http://meilisearch:7700'), $httpClient);
        $provisioner->ensureVectorStoreEnabled();
    }

    #[Test]
    public function ensureVectorStoreEnabledSwallowsRequestFailures(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->method('request')
            ->willThrowException(new RequestException('boom', new Request('PATCH', '/experimental-features')));

        $provisioner = new VectorStoreProvisioner($this->createConnectionFactory('http://meilisearch:7700'), $httpClient);

        // Must not throw — provisioning must not fail just because the flag
        // couldn't be toggled (already enabled, feature removed in a newer
        // Meilisearch version, network hiccup, ...).
        $provisioner->ensureVectorStoreEnabled();
        self::assertTrue(true);
    }
}
