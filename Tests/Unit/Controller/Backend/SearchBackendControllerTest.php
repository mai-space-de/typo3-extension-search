<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Controller\Backend;

use Maispace\MaiSearch\Controller\Backend\SearchBackendController;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\IndexQueueProcessor;
use Maispace\MaiSearch\Domain\Service\ProvisioningService;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class SearchBackendControllerTest extends TestCase
{
    #[Test]
    public function controllerExtendsAbstractBackendController(): void
    {
        self::assertTrue(
            is_subclass_of(
                SearchBackendController::class,
                \Maispace\MaiBase\Controller\Backend\AbstractBackendController::class,
            ),
        );
    }

    #[Test]
    public function controllerHasIndexManagementServiceDependency(): void
    {
        $params = (new \ReflectionMethod(SearchBackendController::class, '__construct'))
            ->getParameters();

        $names = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $params);
        self::assertContains('indexManagementService', $names);

        $param = $this->findParam($params, 'indexManagementService');
        self::assertInstanceOf(\ReflectionNamedType::class, $param->getType());
        self::assertSame(IndexManagementService::class, $param->getType()->getName());
    }

    #[Test]
    public function controllerHasIndexerRegistryDependency(): void
    {
        $params = (new \ReflectionMethod(SearchBackendController::class, '__construct'))
            ->getParameters();

        $param = $this->findParam($params, 'indexerRegistry');
        self::assertInstanceOf(\ReflectionNamedType::class, $param->getType());
        self::assertSame(IndexerRegistry::class, $param->getType()->getName());
    }

    #[Test]
    public function controllerHasProvisioningServiceDependency(): void
    {
        $params = (new \ReflectionMethod(SearchBackendController::class, '__construct'))
            ->getParameters();

        $param = $this->findParam($params, 'provisioningService');
        self::assertInstanceOf(\ReflectionNamedType::class, $param->getType());
        self::assertSame(ProvisioningService::class, $param->getType()->getName());
    }

    #[Test]
    public function indexActionMethodExists(): void
    {
        self::assertTrue(method_exists(SearchBackendController::class, 'indexAction'));
    }

    #[Test]
    public function indexActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(SearchBackendController::class, 'indexAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function reindexActionMethodExists(): void
    {
        self::assertTrue(method_exists(SearchBackendController::class, 'reindexAction'));
    }

    #[Test]
    public function reindexActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(SearchBackendController::class, 'reindexAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function clearActionMethodExists(): void
    {
        self::assertTrue(method_exists(SearchBackendController::class, 'clearAction'));
    }

    #[Test]
    public function clearActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(SearchBackendController::class, 'clearAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function controllerHasAnswerCacheServiceDependency(): void
    {
        $params = (new \ReflectionMethod(SearchBackendController::class, '__construct'))
            ->getParameters();

        $param = $this->findParam($params, 'answerCacheService');
        self::assertInstanceOf(\ReflectionNamedType::class, $param->getType());
        self::assertSame(AnswerCacheService::class, $param->getType()->getName());
    }

    #[Test]
    public function clearAnswerCacheActionMethodExists(): void
    {
        self::assertTrue(method_exists(SearchBackendController::class, 'clearAnswerCacheAction'));
    }

    #[Test]
    public function clearAnswerCacheActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(SearchBackendController::class, 'clearAnswerCacheAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function provisionActionMethodExists(): void
    {
        self::assertTrue(method_exists(SearchBackendController::class, 'provisionAction'));
    }

    #[Test]
    public function provisionActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(SearchBackendController::class, 'provisionAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function controllerHasIndexQueueRepositoryDependency(): void
    {
        $params = (new \ReflectionMethod(SearchBackendController::class, '__construct'))
            ->getParameters();

        $param = $this->findParam($params, 'indexQueueRepository');
        self::assertInstanceOf(\ReflectionNamedType::class, $param->getType());
        self::assertSame(IndexQueueRepository::class, $param->getType()->getName());
    }

    #[Test]
    public function controllerHasIndexQueueProcessorDependency(): void
    {
        $params = (new \ReflectionMethod(SearchBackendController::class, '__construct'))
            ->getParameters();

        $param = $this->findParam($params, 'indexQueueProcessor');
        self::assertInstanceOf(\ReflectionNamedType::class, $param->getType());
        self::assertSame(IndexQueueProcessor::class, $param->getType()->getName());
    }

    #[Test]
    public function controllerHasRagPromptFactoryDependency(): void
    {
        $params = (new \ReflectionMethod(SearchBackendController::class, '__construct'))
            ->getParameters();

        $param = $this->findParam($params, 'ragPromptFactory');
        self::assertInstanceOf(\ReflectionNamedType::class, $param->getType());
        self::assertSame(
            \Maispace\MaiSearch\Configuration\RagPromptFactory::class,
            $param->getType()->getName(),
        );
    }

    #[Test]
    public function queueActionMethodExists(): void
    {
        self::assertTrue(method_exists(SearchBackendController::class, 'queueAction'));
    }

    #[Test]
    public function queueActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(SearchBackendController::class, 'queueAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function queueStatusActionMethodExists(): void
    {
        self::assertTrue(method_exists(SearchBackendController::class, 'queueStatusAction'));
    }

    #[Test]
    public function queueStatusActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(SearchBackendController::class, 'queueStatusAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function processQueueActionMethodExists(): void
    {
        self::assertTrue(method_exists(SearchBackendController::class, 'processQueueAction'));
    }

    #[Test]
    public function processQueueActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(SearchBackendController::class, 'processQueueAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function formatJobsForDisplayFormatsTimestampsAndCastsTypes(): void
    {
        $controller = new \ReflectionClass(SearchBackendController::class)->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchBackendController::class, 'formatJobsForDisplay');

        $result = $method->invoke($controller, [
            [
                'uid' => '1',
                'table_name' => 'pages',
                'record_uid' => '42',
                'operation' => 'index',
                'status' => 'done',
                'error_message' => '',
                'queued_date' => '1000',
                'processed_date' => '0',
            ],
        ]);

        self::assertSame(1, $result[0]['uid']);
        self::assertSame('pages', $result[0]['tableName']);
        self::assertSame(42, $result[0]['recordUid']);
        self::assertSame(date('Y-m-d H:i:s', 1000), $result[0]['queuedDate']);
        self::assertSame('', $result[0]['processedDate']);
    }

    #[Test]
    public function buildQueueProgressComputesTotalAndPercentages(): void
    {
        $repository = $this->createMock(IndexQueueRepository::class);
        $repository->method('countByStatus')->willReturn([
            'pending' => 1,
            'processing' => 1,
            'done' => 2,
            'failed' => 0,
        ]);

        $controller = new \ReflectionClass(SearchBackendController::class)->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(SearchBackendController::class, 'indexQueueRepository');
        $property->setValue($controller, $repository);

        $method = new \ReflectionMethod(SearchBackendController::class, 'buildQueueProgress');
        $result = $method->invoke($controller);

        self::assertSame(4, $result['total']);
        self::assertSame(25.0, $result['percentages']['pending']);
        self::assertSame(25.0, $result['percentages']['processing']);
        self::assertSame(50.0, $result['percentages']['done']);
        self::assertSame(0.0, $result['percentages']['failed']);
    }

    #[Test]
    public function buildQueueProgressReturnsZeroPercentagesWhenEmpty(): void
    {
        $repository = $this->createMock(IndexQueueRepository::class);
        $repository->method('countByStatus')->willReturn([
            'pending' => 0,
            'processing' => 0,
            'done' => 0,
            'failed' => 0,
        ]);

        $controller = new \ReflectionClass(SearchBackendController::class)->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(SearchBackendController::class, 'indexQueueRepository');
        $property->setValue($controller, $repository);

        $method = new \ReflectionMethod(SearchBackendController::class, 'buildQueueProgress');
        $result = $method->invoke($controller);

        self::assertSame(0, $result['total']);
        self::assertSame(0.0, $result['percentages']['pending']);
        self::assertSame(0.0, $result['percentages']['done']);
    }

    #[Test]
    public function controllerUsesResponseHelpersTrait(): void
    {
        $traits = class_uses(SearchBackendController::class);

        self::assertIsArray($traits);
        self::assertContains(
            \Maispace\MaiBase\Controller\Traits\ResponseHelpersTrait::class,
            $traits,
        );
    }

    /**
     * @param \ReflectionParameter[] $params
     */
    private function findParam(array $params, string $name): \ReflectionParameter
    {
        $matching = array_filter(
            $params,
            static fn(\ReflectionParameter $p) => $p->getName() === $name,
        );

        return current($matching);
    }
}
