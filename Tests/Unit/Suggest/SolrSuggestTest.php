<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Suggest;

use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SearchQuery;
use Maispace\MaiSearch\Domain\Solr\SearchResponse;
use Maispace\MaiSearch\Domain\Solr\SolrClientInterface;
use Maispace\MaiSearch\Suggest\SolrSuggest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class SolrSuggestTest extends TestCase
{
    #[Test]
    public function getIdentifierReturnsSolr(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);

        self::assertSame('solr', (new SolrSuggest($connectionFactory))->getIdentifier());
    }

    #[Test]
    public function getSuggestionsReturnsEmptyArrayForBlankQuery(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->expects(self::never())->method('getConnection');

        self::assertSame([], (new SolrSuggest($connectionFactory))->getSuggestions('   '));
    }

    #[Test]
    public function getSuggestionsBuildsPrefixQueryAndExtractsTitles(): void
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchQuery $query): bool {
                return $query->getQuery() === 'title_s:gymna*' && $query->getRows() === 5;
            }))
            ->willReturn(new SearchResponse([
                ['title_s' => 'Gymnastics for kids'],
                ['title_s' => 'Gymnastics for adults'],
            ], 2));

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getConnection')->willReturn($client);

        $suggestions = (new SolrSuggest($connectionFactory))->getSuggestions('gymna', 5);

        self::assertSame(['Gymnastics for kids', 'Gymnastics for adults'], $suggestions);
    }

    #[Test]
    public function getSuggestionsSkipsDocumentsWithoutATitle(): void
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client->method('search')->willReturn(new SearchResponse([
            ['other_field' => 'no title here'],
        ], 1));

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getConnection')->willReturn($client);

        self::assertSame([], (new SolrSuggest($connectionFactory))->getSuggestions('test'));
    }

    #[Test]
    public function getSuggestionsEscapesSolrSpecialCharacters(): void
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static fn(SearchQuery $query): bool => $query->getQuery() === 'title_s:foo\\+bar*'))
            ->willReturn(new SearchResponse([], 0));

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getConnection')->willReturn($client);

        (new SolrSuggest($connectionFactory))->getSuggestions('foo+bar');
    }

    #[Test]
    public function getSuggestionsReturnsEmptyArrayOnException(): void
    {
        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->method('getConnection')->willThrowException(new \RuntimeException('Solr unreachable'));

        self::assertSame([], (new SolrSuggest($connectionFactory))->getSuggestions('test'));
    }

    #[Test]
    public function getSuggestionsPassesLanguageToConnectionFactory(): void
    {
        $language = $this->createMock(SiteLanguage::class);
        $client = $this->createMock(SolrClientInterface::class);
        $client->method('search')->willReturn(new SearchResponse([], 0));

        $connectionFactory = $this->createMock(ConnectionFactory::class);
        $connectionFactory->expects(self::once())->method('getConnection')->with($language)->willReturn($client);

        (new SolrSuggest($connectionFactory))->getSuggestions('test', 10, $language);
    }
}
