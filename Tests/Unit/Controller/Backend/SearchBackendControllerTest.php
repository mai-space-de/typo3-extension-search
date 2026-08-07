<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Controller\Backend;

use Maispace\MaiSearch\Controller\Backend\SearchBackendController;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\IndexQueueProcessor;
use Maispace\MaiSearch\Domain\Service\ProvisioningService;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
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
    public function controllerHasConnectionFactoryDependency(): void
    {
        $params = (new \ReflectionMethod(SearchBackendController::class, '__construct'))
            ->getParameters();

        $param = $this->findParam($params, 'connectionFactory');
        self::assertInstanceOf(\ReflectionNamedType::class, $param->getType());
        self::assertSame(ConnectionFactory::class, $param->getType()->getName());
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
