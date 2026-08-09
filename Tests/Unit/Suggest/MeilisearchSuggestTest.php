<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Suggest;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory;
use Maispace\MaiSearch\Backend\Meilisearch\IndexNameResolver;
use Maispace\MaiSearch\Backend\Meilisearch\MeilisearchClientInterface;
use Maispace\MaiSearch\Backend\Meilisearch\SearchResponse;
use Maispace\MaiSearch\Suggest\MeilisearchSuggest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class MeilisearchSuggestTest extends TestCase
{
    #[Test]
    public function getIdentifierReturnsMeilisearch(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);

        $subject = new MeilisearchSuggest($connectionFactory, new IndexNameResolver());

        self::assertSame('meilisearch', $subject->getIdentifier());
    }

    #[Test]
    public function getSuggestionsReturnsEmptyArrayForBlankQuery(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->expects(self::never())->method('getClient');

        $subject = new MeilisearchSuggest($connectionFactory, new IndexNameResolver());

        self::assertSame([], $subject->getSuggestions('  '));
    }

    #[Test]
    public function getSuggestionsQueriesTheDefaultIndexAndExtractsTitles(): void
    {
        $client = $this->createMock(MeilisearchClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with('mai_search', 'gymna', ['limit' => 5, 'attributesToRetrieve' => ['title']])
            ->willReturn(new SearchResponse(
                hits: [['title' => 'Gymnastics for kids'], ['title' => 'Gymnastics for adults']],
                estimatedTotalHits: 2,
                processingTimeMs: 1,
            ));

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getClient')->willReturn($client);

        $subject = new MeilisearchSuggest($connectionFactory, new IndexNameResolver());

        self::assertSame(
            ['Gymnastics for kids', 'Gymnastics for adults'],
            $subject->getSuggestions('gymna', 5),
        );
    }

    #[Test]
    public function getSuggestionsSkipsHitsWithoutATitle(): void
    {
        $client = $this->createMock(MeilisearchClientInterface::class);
        $client->method('search')->willReturn(new SearchResponse(
            hits: [['id' => 'no-title']],
            estimatedTotalHits: 1,
            processingTimeMs: 1,
        ));

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getClient')->willReturn($client);

        $subject = new MeilisearchSuggest($connectionFactory, new IndexNameResolver());

        self::assertSame([], $subject->getSuggestions('test'));
    }

    #[Test]
    public function getSuggestionsReturnsEmptyArrayOnException(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getClient')->willThrowException(new \RuntimeException('unreachable'));

        $subject = new MeilisearchSuggest($connectionFactory, new IndexNameResolver());

        self::assertSame([], $subject->getSuggestions('test'));
    }

    #[Test]
    public function getSuggestionsQueriesTheLanguageSpecificIndexWhenGiven(): void
    {
        $locale = new Locale('de');
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLocale')->willReturn($locale);

        $client = $this->createMock(MeilisearchClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with('mai_search_de', 'gymna', self::anything())
            ->willReturn(new SearchResponse(hits: [], estimatedTotalHits: 0, processingTimeMs: 1));

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getClient')->willReturn($client);

        $subject = new MeilisearchSuggest($connectionFactory, new IndexNameResolver());
        $subject->getSuggestions('gymna', 10, $language);
    }
}
