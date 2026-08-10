<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Controller;

use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Configuration\SuggestConfiguration;
use Maispace\MaiSearch\Controller\SearchController;
use Maispace\MaiSearch\Domain\Service\RagContextResolver;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Http\RagApiSupport;
use Maispace\MaiSearch\Reranking\RerankerInterface;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\BackendRegistry;
use Maispace\MaiSearch\Service\ProviderAvailabilityService;
use Maispace\MaiSearch\Service\ProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class SearchControllerTest extends TestCase
{
    private SearchService $searchService;
    private SearchSynthesisService $synthesisService;
    private RagContextResolver $ragContextResolver;
    private RagConfiguration $ragConfiguration;
    private SuggestConfiguration $suggestConfiguration;
    private ProviderAvailabilityService $providerAvailability;
    private RagApiSupport $ragApiSupport;

    protected function setUp(): void
    {
        $this->searchService = $this->createMock(SearchService::class);

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn([]);
        $this->ragConfiguration = new RagConfiguration($extensionConfiguration);
        $this->suggestConfiguration = new SuggestConfiguration($extensionConfiguration);

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('maisearch_answer')->willReturn($cache);
        $this->synthesisService = new SearchSynthesisService(
            $this->createMock(ProviderRegistry::class),
            new AnswerCacheService($cacheManager),
            $this->ragConfiguration,
        );
        $backendRegistry = $this->createMock(BackendRegistry::class);
        $backendRegistry->method('getActive')->willReturn($this->createMock(SearchBackendInterface::class));
        $this->ragContextResolver = new RagContextResolver(
            $this->searchService,
            $this->createMock(RerankerInterface::class),
            $backendRegistry,
        );
        $this->providerAvailability = $this->createMock(ProviderAvailabilityService::class);
        $this->providerAvailability->method('isChatProviderAvailable')->willReturn(true);
        $this->ragApiSupport = new RagApiSupport();
    }

    private function createController(): SearchController
    {
        return new SearchController(
            $this->searchService,
            $this->synthesisService,
            $this->ragContextResolver,
            $this->ragConfiguration,
            $this->suggestConfiguration,
            $this->providerAvailability,
            $this->ragApiSupport,
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
    public function resultsActionIsRateLimited(): void
    {
        $attributes = (new \ReflectionMethod(SearchController::class, 'resultsAction'))
            ->getAttributes(\TYPO3\CMS\Extbase\Attribute\RateLimit::class);

        self::assertCount(1, $attributes);

        /** @var \TYPO3\CMS\Extbase\Attribute\RateLimit $rateLimit */
        $rateLimit = $attributes[0]->newInstance();
        self::assertSame(30, $rateLimit->limit);
        self::assertSame('1 minute', $rateLimit->interval);
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
        $controller = $this->createController();

        $method = new \ReflectionMethod(SearchController::class, 'resolveRagEnabled');
        self::assertFalse($method->invoke($controller));
    }

    #[Test]
    public function resolveRagEnabledReturnsTrueWhenSettingIsEnabled(): void
    {
        $controller = $this->createController();

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
        $controller = $this->createController();

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
        $controller = $this->createController();

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
        $controller = $this->createController();

        $language = $this->createMock(SiteLanguage::class);

        $request = $this->createMock(\TYPO3\CMS\Extbase\Mvc\Request::class);
        $request->method('getAttribute')->with('language')->willReturn($language);

        $requestProperty = new \ReflectionProperty(SearchController::class, 'request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $method = new \ReflectionMethod(SearchController::class, 'resolveCurrentLanguage');
        self::assertSame($language, $method->invoke($controller));
    }

    // ── RAG addendum: reranking / conversation history / streaming wiring ────

    #[Test]
    public function controllerHasRagContextResolverDependency(): void
    {
        $params = (new \ReflectionMethod(SearchController::class, '__construct'))->getParameters();

        $param = current(array_filter($params, static fn(\ReflectionParameter $p) => $p->getName() === 'ragContextResolver'));

        self::assertNotFalse($param);
        self::assertInstanceOf(\ReflectionNamedType::class, $param->getType());
        self::assertSame(RagContextResolver::class, $param->getType()->getName());
    }

    #[Test]
    public function controllerHasRagConfigurationDependency(): void
    {
        $params = (new \ReflectionMethod(SearchController::class, '__construct'))->getParameters();

        $param = current(array_filter($params, static fn(\ReflectionParameter $p) => $p->getName() === 'ragConfiguration'));

        self::assertNotFalse($param);
        self::assertInstanceOf(\ReflectionNamedType::class, $param->getType());
        self::assertSame(RagConfiguration::class, $param->getType()->getName());
    }

    #[Test]
    public function resultsActionAcceptsHistoryParameterDefaultingToEmptyString(): void
    {
        $params = (new \ReflectionMethod(SearchController::class, 'resultsAction'))->getParameters();

        $historyParam = current(array_filter($params, static fn(\ReflectionParameter $p) => $p->getName() === 'history'));

        self::assertNotFalse($historyParam);
        self::assertTrue($historyParam->isDefaultValueAvailable());
        self::assertSame('', $historyParam->getDefaultValue());
    }

    #[Test]
    public function decodeHistoryReturnsEmptyHistoryForEmptyString(): void
    {
        $controller = $this->createController();

        $method = new \ReflectionMethod(SearchController::class, 'decodeHistory');
        $history = $method->invoke($controller, '');

        self::assertTrue($history->isEmpty());
    }

    #[Test]
    public function decodeHistoryReturnsEmptyHistoryForMalformedJson(): void
    {
        $controller = $this->createController();

        $method = new \ReflectionMethod(SearchController::class, 'decodeHistory');
        $history = $method->invoke($controller, '{not valid json');

        self::assertTrue($history->isEmpty());
    }

    #[Test]
    public function decodeHistoryParsesAValidPayload(): void
    {
        $controller = $this->createController();

        $method = new \ReflectionMethod(SearchController::class, 'decodeHistory');
        $history = $method->invoke($controller, json_encode([
            ['role' => 'user', 'content' => 'Q1'],
            ['role' => 'assistant', 'content' => 'A1'],
        ]));

        self::assertCount(2, $history);
    }

    #[Test]
    public function appendTurnAddsUserAndAssistantMessagesAndTruncates(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn(['ragHistoryMaxTurns' => '2']);
        $ragConfiguration = new RagConfiguration($extensionConfiguration);

        $controller = new SearchController(
            $this->searchService,
            $this->synthesisService,
            $this->ragContextResolver,
            $ragConfiguration,
            $this->suggestConfiguration,
            $this->providerAvailability,
            $this->ragApiSupport,
        );

        $method = new \ReflectionMethod(SearchController::class, 'appendTurn');
        $history = $method->invoke(
            $controller,
            \Maispace\MaiSearch\Domain\Model\ConversationHistory::empty()->withUserMessage('Q1')->withAssistantMessage('A1'),
            'Q2',
            'A2',
        );

        self::assertCount(2, $history);
        self::assertSame(['Q2', 'A2'], array_map(static fn($m) => $m->content, $history->getMessages()));
    }

    #[Test]
    public function encodeHistoryProducesJsonRoundTrippableByConversationHistory(): void
    {
        $controller = $this->createController();

        $history = \Maispace\MaiSearch\Domain\Model\ConversationHistory::empty()->withUserMessage('Q1');

        $encodeMethod = new \ReflectionMethod(SearchController::class, 'encodeHistory');
        $json = $encodeMethod->invoke($controller, $history);

        $decodeMethod = new \ReflectionMethod(SearchController::class, 'decodeHistory');
        $decoded = $decodeMethod->invoke($controller, $json);

        self::assertSame($history->toArray(), $decoded->toArray());
    }
}
