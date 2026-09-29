<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Backend\Meilisearch;

use Maispace\MaiSearch\Backend\Meilisearch\IndexSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexSettingsTest extends TestCase
{
    #[Test]
    public function documentSettingsRankTitleBeforeContent(): void
    {
        $settings = IndexSettings::forDocuments('de');

        self::assertSame(['title', 'content'], $settings['searchableAttributes']);
        self::assertContains('in', $settings['stopWords']);
        self::assertContains('was', $settings['stopWords']);
        self::assertSame(IndexSettings::RANKING_RULES, $settings['rankingRules']);
    }

    #[Test]
    public function chunkSettingsSearchParentTitleAndContent(): void
    {
        $settings = IndexSettings::forChunks('de');

        self::assertSame(['parentTitle', 'content'], $settings['searchableAttributes']);
        self::assertContains('in', $settings['stopWords']);
    }

    #[Test]
    public function englishStopWordsAreUsedForEnIndexes(): void
    {
        self::assertContains('the', IndexSettings::stopWordsFor('en'));
        self::assertContains('what', IndexSettings::stopWordsFor('en'));
        self::assertNotContains('der', IndexSettings::stopWordsFor('en'));
    }

    #[Test]
    public function missingLanguageFallsBackToGermanStopWords(): void
    {
        self::assertContains('der', IndexSettings::stopWordsFor(null));
        self::assertContains('der', IndexSettings::stopWordsFor('de-DE'));
    }

    #[Test]
    public function keywordQueryStripsCapitalizedGermanQuestionWords(): void
    {
        self::assertSame(
            'Ladies Club?',
            IndexSettings::keywordQuery('Was ist der Ladies Club?', 'de'),
        );
    }

    #[Test]
    public function keywordQueryKeepsOriginalWhenOnlyStopWordsRemain(): void
    {
        self::assertSame('Was ist der', IndexSettings::keywordQuery('Was ist der', 'de'));
    }
}
