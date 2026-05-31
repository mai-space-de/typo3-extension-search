<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Solr;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaManagerTest extends TestCase
{
    #[Test]
    public function constantsHaveExpectedValues(): void
    {
        self::assertSame('content_vector', SchemaManager::VECTOR_FIELD_NAME);
        self::assertSame(1536, SchemaManager::VECTOR_DIMENSIONS);
        self::assertSame('knn_vector_1536', SchemaManager::VECTOR_FIELD_TYPE_NAME);
    }

    #[Test]
    public function buildFieldTypePayloadReturnsCorrectStructure(): void
    {
        $manager = $this->createManagerWithNoHttp();

        $payload = $manager->buildFieldTypePayload();

        self::assertArrayHasKey('add-field-type', $payload);
        $fieldType = $payload['add-field-type'];
        self::assertSame('knn_vector_1536', $fieldType['name']);
        self::assertSame('solr.DenseVectorField', $fieldType['class']);
        self::assertSame(1536, $fieldType['vectorDimension']);
        self::assertSame('float32', $fieldType['vectorEncoding']);
        self::assertSame('cosine', $fieldType['similarityFunction']);
    }

    #[Test]
    public function buildFieldPayloadReturnsCorrectStructure(): void
    {
        $manager = $this->createManagerWithNoHttp();

        $payload = $manager->buildFieldPayload();

        self::assertArrayHasKey('add-field', $payload);
        $field = $payload['add-field'];
        self::assertSame('content_vector', $field['name']);
        self::assertSame('knn_vector_1536', $field['type']);
        self::assertTrue($field['indexed']);
        self::assertTrue($field['stored']);
        self::assertFalse($field['multiValued']);
    }

    #[Test]
    public function ensureVectorFieldExistsInAllCoresDoesNothingWhenCoreMappingIsEmpty(): void
    {
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->method('getCoreMapping')->willReturn([]);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $manager = new SchemaManager($factory, $httpClient);
        $manager->ensureVectorFieldExistsInAllCores();
    }

    #[Test]
    public function ensureVectorFieldExistsInAllCoresCallsEachCore(): void
    {
        $endpoint = new \Solarium\Core\Client\Endpoint([
            'scheme' => 'http',
            'host' => 'localhost',
            'port' => 8983,
            'core' => 'core_en',
        ]);

        $readService = $this->createConfiguredMock(
            \ApacheSolrForTypo3\Solr\System\Solr\Service\SolrReadService::class,
            ['getPrimaryEndpoint' => $endpoint],
        );

        $connection = $this->createMock(\ApacheSolrForTypo3\Solr\System\Solr\SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $factory = $this->createMock(ConnectionFactory::class);
        $factory->method('getCoreMapping')->willReturn(['en' => 'core_en', 'de' => 'core_de']);
        $factory->method('getConnectionForLanguageCode')->willReturn($connection);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->expects(self::exactly(4))
            ->method('request')
            ->willReturn(new Response(200));

        $manager = new SchemaManager($factory, $httpClient);
        $manager->ensureVectorFieldExistsInAllCores();
    }

    #[Test]
    public function ensureVectorFieldExistsPostsFieldTypeAndFieldPayloads(): void
    {
        $endpoint = new \Solarium\Core\Client\Endpoint([
            'scheme' => 'http',
            'host' => 'localhost',
            'port' => 8983,
            'core' => 'core_en',
        ]);

        $readService = $this->createConfiguredMock(
            \ApacheSolrForTypo3\Solr\System\Solr\Service\SolrReadService::class,
            ['getPrimaryEndpoint' => $endpoint],
        );

        $connection = $this->createMock(\ApacheSolrForTypo3\Solr\System\Solr\SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $factory = $this->createMock(ConnectionFactory::class);
        $factory->method('getConnectionForLanguageCode')->willReturn($connection);

        $capturedPayloads = [];
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$capturedPayloads) {
                $capturedPayloads[] = $options['json'];
                return new Response(200);
            });

        $manager = new SchemaManager($factory, $httpClient);
        $manager->ensureVectorFieldExists('core_en');

        self::assertCount(2, $capturedPayloads);
        self::assertArrayHasKey('add-field-type', $capturedPayloads[0]);
        self::assertArrayHasKey('add-field', $capturedPayloads[1]);
    }

    #[Test]
    public function ensureVectorFieldExistsSilentlyIgnoresAlreadyExistsError(): void
    {
        $endpoint = new \Solarium\Core\Client\Endpoint([
            'scheme' => 'http',
            'host' => 'localhost',
            'port' => 8983,
            'core' => 'core_en',
        ]);

        $readService = $this->createConfiguredMock(
            \ApacheSolrForTypo3\Solr\System\Solr\Service\SolrReadService::class,
            ['getPrimaryEndpoint' => $endpoint],
        );

        $connection = $this->createMock(\ApacheSolrForTypo3\Solr\System\Solr\SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $factory = $this->createMock(ConnectionFactory::class);
        $factory->method('getConnectionForLanguageCode')->willReturn($connection);

        $alreadyExistsException = new RequestException(
            'Client error: 400 Bad Request — Field type already exists',
            new Request('POST', 'http://localhost:8983/solr/core_en/schema'),
        );

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willThrowException($alreadyExistsException);

        $manager = new SchemaManager($factory, $httpClient);
        $manager->ensureVectorFieldExists('core_en');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function ensureVectorFieldExistsSilentlyIgnoresAlreadyExistsErrorFromResponseBody(): void
    {
        $endpoint = new \Solarium\Core\Client\Endpoint([
            'scheme' => 'http',
            'host' => 'localhost',
            'port' => 8983,
            'core' => 'core_en',
        ]);

        $readService = $this->createConfiguredMock(
            \ApacheSolrForTypo3\Solr\System\Solr\Service\SolrReadService::class,
            ['getPrimaryEndpoint' => $endpoint],
        );

        $connection = $this->createMock(\ApacheSolrForTypo3\Solr\System\Solr\SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $factory = $this->createMock(ConnectionFactory::class);
        $factory->method('getConnectionForLanguageCode')->willReturn($connection);

        $alreadyExistsException = new RequestException(
            'Client error: `POST http://solr:8983/solr/core_en/schema` resulted in a `400 Bad Request` response',
            new Request('POST', 'http://solr:8983/solr/core_en/schema'),
            new Response(
                400,
                [],
                '{"error":{"details":[{"errorMessages":["Field type \'knn_vector_1536\' already exists.\\n"]}]}}',
            ),
        );

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willThrowException($alreadyExistsException);

        $manager = new SchemaManager($factory, $httpClient);
        $manager->ensureVectorFieldExists('core_en');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function ensureVectorFieldExistsThrowsRuntimeExceptionForUnexpectedError(): void
    {
        $endpoint = new \Solarium\Core\Client\Endpoint([
            'scheme' => 'http',
            'host' => 'localhost',
            'port' => 8983,
            'core' => 'core_en',
        ]);

        $readService = $this->createConfiguredMock(
            \ApacheSolrForTypo3\Solr\System\Solr\Service\SolrReadService::class,
            ['getPrimaryEndpoint' => $endpoint],
        );

        $connection = $this->createMock(\ApacheSolrForTypo3\Solr\System\Solr\SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $factory = $this->createMock(ConnectionFactory::class);
        $factory->method('getConnectionForLanguageCode')->willReturn($connection);

        $networkError = new RequestException(
            'cURL error 7: Failed to connect to localhost port 8983',
            new Request('POST', 'http://localhost:8983/solr/core_en/schema'),
        );

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('request')->willThrowException($networkError);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Solr Schema API request failed for core "core_en"');

        $manager = new SchemaManager($factory, $httpClient);
        $manager->ensureVectorFieldExists('core_en');
    }

    private function createManagerWithNoHttp(): SchemaManager
    {
        $factory = $this->createMock(ConnectionFactory::class);
        $httpClient = $this->createMock(ClientInterface::class);

        return new SchemaManager($factory, $httpClient);
    }
}
