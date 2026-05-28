<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Controller;

use Maispace\MaiSearch\Controller\SearchController;
use Maispace\MaiSearch\Domain\Service\SearchService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class SearchControllerTest extends TestCase
{
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
        $searchService = $this->createMock(SearchService::class);
        $controller = new SearchController($searchService);

        $method = new \ReflectionMethod(SearchController::class, 'resolveRagEnabled');
        self::assertFalse($method->invoke($controller));
    }

    #[Test]
    public function resolveRagEnabledReturnsTrueWhenSettingIsEnabled(): void
    {
        $searchService = $this->createMock(SearchService::class);
        $controller = new SearchController($searchService);

        // Set settings via reflection to simulate TypoScript configuration
        $settingsProperty = new \ReflectionProperty(
            SearchController::class,
            'settings',
        );
        $settingsProperty->setAccessible(true);
        $settingsProperty->setValue($controller, ['ragEnabled' => '1']);

        $method = new \ReflectionMethod(SearchController::class, 'resolveRagEnabled');
        self::assertTrue($method->invoke($controller));

        // Also test with boolean true
        $settingsProperty->setValue($controller, ['ragEnabled' => true]);
        self::assertTrue($method->invoke($controller));
    }

    #[Test]
    public function resolveRagEnabledReturnsFalseWhenExplicitlyDisabled(): void
    {
        $searchService = $this->createMock(SearchService::class);
        $controller = new SearchController($searchService);

        $settingsProperty = new \ReflectionProperty(
            SearchController::class,
            'settings',
        );
        $settingsProperty->setAccessible(true);
        $settingsProperty->setValue($controller, ['ragEnabled' => '0']);

        $method = new \ReflectionMethod(SearchController::class, 'resolveRagEnabled');
        self::assertFalse($method->invoke($controller));

        // Also test with boolean false
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
        $searchService = $this->createMock(SearchService::class);
        $controller = new SearchController($searchService);

        // Set up a mock request without a language attribute
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
        $searchService = $this->createMock(SearchService::class);
        $controller = new SearchController($searchService);

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
