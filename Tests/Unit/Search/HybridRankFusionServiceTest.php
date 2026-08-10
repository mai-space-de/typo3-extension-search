<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Search;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Search\HybridRankFusionService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HybridRankFusionServiceTest extends TestCase
{
    private HybridRankFusionService $subject;

    protected function setUp(): void
    {
        $this->subject = new HybridRankFusionService();
    }

    private function makeResult(string $url, float $score = 1.0, string $type = 'page'): SearchResult
    {
        return new SearchResult($type, 'Title ' . $url, 'Snippet', $url, 'icon', null, $score);
    }

    #[Test]
    public function fuseReturnsTextResultsUnchangedWhenSemanticResultsAreEmpty(): void
    {
        $textResults = [$this->makeResult('/a'), $this->makeResult('/b')];

        self::assertSame($textResults, $this->subject->fuse($textResults, []));
    }

    #[Test]
    public function fuseReturnsEmptyArrayWhenTextResultsAreEmpty(): void
    {
        self::assertSame([], $this->subject->fuse([], [$this->makeResult('/a')]));
    }

    #[Test]
    public function fuseNeverAddsDocumentsNotInTextResults(): void
    {
        $textResults = [$this->makeResult('/a'), $this->makeResult('/b')];
        $semanticResults = [$this->makeResult('/c')];

        $fused = $this->subject->fuse($textResults, $semanticResults);

        self::assertCount(2, $fused);
        self::assertSame(['/a', '/b'], array_map(static fn(SearchResult $r): string => $r->url, $fused));
    }

    #[Test]
    public function fusePromotesADocumentRankedHighInBothSignals(): void
    {
        // /b is 2nd in text but 1st in semantic — RRF should be able to promote it above /a.
        $textResults = [$this->makeResult('/a'), $this->makeResult('/b'), $this->makeResult('/c')];
        $semanticResults = [$this->makeResult('/b'), $this->makeResult('/c'), $this->makeResult('/a')];

        $fused = $this->subject->fuse($textResults, $semanticResults);

        self::assertSame('/b', $fused[0]->url);
    }

    #[Test]
    public function fuseKeepsOriginalOrderWhenSemanticSignalAgrees(): void
    {
        $textResults = [$this->makeResult('/a'), $this->makeResult('/b'), $this->makeResult('/c')];
        $semanticResults = [$this->makeResult('/a'), $this->makeResult('/b'), $this->makeResult('/c')];

        $fused = $this->subject->fuse($textResults, $semanticResults);

        self::assertSame(['/a', '/b', '/c'], array_map(static fn(SearchResult $r): string => $r->url, $fused));
    }

    #[Test]
    public function collapseToBestPerParentKeepsOnlyHighestScoringChunkPerUrl(): void
    {
        $chunks = [
            $this->makeResult('/a', score: 0.5),
            $this->makeResult('/a', score: 0.9),
            $this->makeResult('/a', score: 0.3),
            $this->makeResult('/b', score: 0.7),
        ];

        $collapsed = $this->subject->collapseToBestPerParent($chunks);

        self::assertCount(2, $collapsed);
        $byUrl = [];
        foreach ($collapsed as $result) {
            $byUrl[$result->url] = $result;
        }
        self::assertSame(0.9, $byUrl['/a']->score);
        self::assertSame(0.7, $byUrl['/b']->score);
    }

    #[Test]
    public function collapseToBestPerParentReturnsEmptyForEmptyInput(): void
    {
        self::assertSame([], $this->subject->collapseToBestPerParent([]));
    }
}
