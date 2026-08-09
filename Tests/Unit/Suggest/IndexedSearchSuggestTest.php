<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Suggest;

use Maispace\MaiSearch\Suggest\IndexedSearchSuggest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\IndexedSearch\Domain\Repository\IndexSearchRepository;
use TYPO3\CMS\IndexedSearch\Lexer;

final class IndexedSearchSuggestTest extends TestCase
{
    private IndexSearchRepository $searchRepository;
    private Lexer $lexer;
    private IndexedSearchSuggest $subject;

    protected function setUp(): void
    {
        $this->searchRepository = $this->createMock(IndexSearchRepository::class);
        $this->lexer = $this->createMock(Lexer::class);
        $this->subject = new IndexedSearchSuggest($this->searchRepository, $this->lexer);
    }

    #[Test]
    public function getIdentifierReturnsIndexedSearch(): void
    {
        self::assertSame('indexed_search', $this->subject->getIdentifier());
    }

    #[Test]
    public function getSuggestionsReturnsEmptyArrayForBlankQuery(): void
    {
        $this->searchRepository->expects(self::never())->method('doSearch');

        self::assertSame([], $this->subject->getSuggestions('   '));
    }

    #[Test]
    public function getSuggestionsReturnsEmptyArrayWhenRepositoryFindsNothing(): void
    {
        $this->lexer->method('split2Words')->willReturnCallback(static fn(string $word): array => [$word]);
        $this->searchRepository->method('doSearch')->willReturn(false);

        self::assertSame([], $this->subject->getSuggestions('gymnastics'));
    }

    #[Test]
    public function getSuggestionsExtractsTitlesFromResultRows(): void
    {
        $this->lexer->method('split2Words')->willReturnCallback(static fn(string $word): array => [$word]);
        $this->searchRepository->method('doSearch')->willReturn([
            'resultRows' => [
                ['item_title' => 'Gymnastics Course'],
                ['item_title' => 'Gymnastics for kids'],
            ],
            'firstRow' => [],
            'count' => 2,
        ]);

        self::assertSame(
            ['Gymnastics Course', 'Gymnastics for kids'],
            $this->subject->getSuggestions('gymnastics'),
        );
    }

    #[Test]
    public function getSuggestionsSkipsRowsWithoutATitle(): void
    {
        $this->lexer->method('split2Words')->willReturnCallback(static fn(string $word): array => [$word]);
        $this->searchRepository->method('doSearch')->willReturn([
            'resultRows' => [['data_page_id' => 1]],
            'firstRow' => [],
            'count' => 1,
        ]);

        self::assertSame([], $this->subject->getSuggestions('gymnastics'));
    }

    #[Test]
    public function getSuggestionsReturnsEmptyArrayOnException(): void
    {
        $this->lexer->method('split2Words')->willThrowException(new \RuntimeException('boom'));

        self::assertSame([], $this->subject->getSuggestions('gymnastics'));
    }
}
