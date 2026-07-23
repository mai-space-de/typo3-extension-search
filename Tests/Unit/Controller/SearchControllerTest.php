<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Controller;

use Maispace\MaiSearch\Controller\SearchController;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class SearchControllerTest extends TestCase
{
    private SearchService $searchService;
    private SearchSynthesisService $synthesisService;

    protected function setUp(): void
    {
        $this->searchService = $this->createMock(SearchService::class);
        $this->synthesisService = new SearchSynthesisService(
            $this->createMock(\TYPO3\CMS\Core\Http\RequestFactory::class),
            '',
        );
    }

    #[Test]
    public function controllerHasSearchServiceDependency(): void
    {
        $params = (new \ReflectionMethod(SearchController::class, '__construct'))
            ->getParameters();

        $names = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $params);
        self::assertContains('searchService', $names);

        $serviceParam = current(array_filter(
            $params,
            static fn(\ReflectionParameter $p) => $p->getName() === 'searchService',
        ));

        self::assertInstanceOf(\ReflectionNamedType::class, $serviceParam->getType());
        self::assertSame(SearchService::class, $serviceParam->getType()->getName());
    }

    #[Test]
    public function controllerHasSynthesisServiceDependency(): void
    {
        $params = (new \ReflectionMethod(SearchController::class, '__construct'))
            ->getParameters();

        $names = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $params);
        self::assertContains('synthesisService', $names);

        $serviceParam = current(array_filter(
            $params,
            static fn(\ReflectionParameter $p) => $p->getName() === 'synthesisService',
        ));

        self::assertInstanceOf(\ReflectionNamedType::class, $serviceParam->getType());
        self::assertSame(SearchSynthesisService::class, $serviceParam->getType()->getName());
    }

    #[Test]
    public function formActionMethodExists(): void
    {
        self::assertTrue(method_exists(SearchController::class, 'formAction'));
    }

    #[Test]
    public function formActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(SearchController::class, 'formAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function resultsActionMethodExists(): void
    {
        self::assertTrue(method_exists(SearchController::class, 'resultsAction'));
    }

    #[Test]
    public function resultsActionReturnsResponseInterface(): void
    {
        $returnType = (new \ReflectionMethod(SearchController::class, 'resultsAction'))
            ->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertSame(ResponseInterface::class, $returnType->getName());
    }

    #[Test]
    public function resultsActionAcceptsTypeAndPageParameters(): void
    {
        $params = (new \ReflectionMethod(SearchController::class, 'resultsAction'))
            ->getParameters();

        $names = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $params);
        self::assertContains('type', $names);
        self::assertContains('page', $names);
    }

    #[Test]
    public function resultsActionAcceptsQueryParameter(): void
    {
        $params = (new \ReflectionMethod(SearchController::class, 'resultsAction'))
            ->getParameters();

        $names = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $params);
        self::assertContains('query', $names);

        $queryParam = current(array_filter(
            $params,
            static fn(\ReflectionParameter $p) => $p->getName() === 'query',
        ));

        $type = $queryParam->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame('string', $type->getName());
        self::assertTrue($queryParam->isDefaultValueAvailable());
        self::assertSame('', $queryParam->getDefaultValue());
    }

    #[Test]
    public function resolveRagEnabledReturnsFalseByDefault(): void
    {
        $controller = new SearchController($this->searchService, $this->synthesisService);

        $method = new \ReflectionMethod(SearchController::class, 'resolveRagEnabled');
        self::assertFalse($method->invoke($controller));
    }

    #[Test]
    public function resolveRagEnabledReturnsTrueWhenSettingIsEnabled(): void
    {
        $controller = new SearchController($this->searchService, $this->synthesisService);

        $settingsProperty = new \ReflectionProperty(
            SearchController::class,
            'settings',
        );
        $settingsProperty->setAccessible(true);
        $settingsProperty->setValue($controller, ['ragEnabled' => '1']);

        $method = new \ReflectionMethod(SearchController::class, 'resolveRagEnabled');
        self::assertTrue($method->invoke($controller));

        $settingsProperty->setValue($controller, ['ragEnabled' => true]);
        self::assertTrue($method->invoke($controller));
    }

    #[Test]
    public function resolveRagEnabledReturnsFalseWhenExplicitlyDisabled(): void
    {
        $controller = new SearchController($this->searchService, $this->synthesisService);

        $settingsProperty = new \ReflectionProperty(
            SearchController::class,
            'settings',
        );
        $settingsProperty->setAccessible(true);
        $settingsProperty->setValue($controller, ['ragEnabled' => '0']);

        $method = new \ReflectionMethod(SearchController::class, 'resolveRagEnabled');
        self::assertFalse($method->invoke($controller));

        $settingsProperty->setValue($controller, ['ragEnabled' => false]);
        self::assertFalse($method->invoke($controller));
    }

    #[Test]
    public function settingsInheritedFromActionController(): void
    {
        $reflectionClass = new \ReflectionClass(SearchController::class);

        self::assertTrue($reflectionClass->hasProperty('settings'));

        $property = $reflectionClass->getProperty('settings');
        self::assertTrue($property->hasType());
        self::assertSame('array', $property->getType()->getName());
    }

    #[Test]
    public function resolveCurrentLanguageMethodExists(): void
    {
        self::assertTrue(method_exists(SearchController::class, 'resolveCurrentLanguage'));
    }

    #[Test]
    public function resolveCurrentLanguageReturnsNullWhenNoLanguageInRequest(): void
    {
        $controller = new SearchController($this->searchService, $this->synthesisService);

        $request = $this->createMock(\TYPO3\CMS\Extbase\Mvc\Request::class);
        $request->method('getAttribute')->with('language')->willReturn(null);

        $requestProperty = new \ReflectionProperty(SearchController::class, 'request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $method = new \ReflectionMethod(SearchController::class, 'resolveCurrentLanguage');
        self::assertNull($method->invoke($controller));
    }

    #[Test]
    public function resolveCurrentLanguageReturnsSiteLanguageWhenPresent(): void
    {
        $controller = new SearchController($this->searchService, $this->synthesisService);

        $language = $this->createMock(SiteLanguage::class);

        $request = $this->createMock(\TYPO3\CMS\Extbase\Mvc\Request::class);
        $request->method('getAttribute')->with('language')->willReturn($language);

        $requestProperty = new \ReflectionProperty(SearchController::class, 'request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $method = new \ReflectionMethod(SearchController::class, 'resolveCurrentLanguage');
        self::assertSame($language, $method->invoke($controller));
    }
}
