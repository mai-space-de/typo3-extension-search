<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Controller\Backend;

use Maispace\MaiSearch\Controller\Backend\SearchBackendController;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Service\IndexerRegistry;
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
