<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Indexer;

use Maispace\MaiSearch\Indexer\PageIndexer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageIndexerTest extends TestCase
{
    private PageIndexer $subject;

    protected function setUp(): void
    {
        $this->subject = new PageIndexer();
    }

    #[Test]
    public function getTypeReturnsPage(): void
    {
        self::assertSame('page', $this->subject->getType());
    }

    #[Test]
    public function supportsPagesTable(): void
    {
        self::assertTrue($this->subject->supports('pages'));
    }

    #[Test]
    public function supportsTtContentTable(): void
    {
        self::assertTrue($this->subject->supports('tt_content'));
    }

    #[Test]
    public function doesNotSupportOtherTables(): void
    {
        self::assertFalse($this->subject->supports('tx_mainews_news'));
        self::assertFalse($this->subject->supports('tx_maifaq_question'));
        self::assertFalse($this->subject->supports('tx_maievents_event'));
    }

    #[Test]
    public function getBoostReturnsHigherValueThanDefault(): void
    {
        self::assertGreaterThan(1.0, $this->subject->getBoost('page'));
    }

    #[Test]
    public function getIconReturnsExpectedValue(): void
    {
        self::assertSame('apps-pagetree-page-default', $this->subject->getIcon('page'));
    }

    #[Test]
    public function formatResultReturnsSearchResultWithCorrectType(): void
    {
        $solrDoc = [
            'title_s' => 'Home Page',
            'content_t' => 'Welcome to our website',
            'url_s' => '/home',
            'score' => 3.0,
        ];

        $result = $this->subject->formatResult($solrDoc);

        self::assertSame('page', $result->type);
        self::assertSame('Home Page', $result->title);
        self::assertSame('/home', $result->url);
        self::assertSame('apps-pagetree-page-default', $result->icon);
        self::assertSame(3.0, $result->score);
    }

    #[Test]
    public function formatResultDefaultsToEmptyStringsWhenFieldsAreMissing(): void
    {
        $result = $this->subject->formatResult([]);

        self::assertSame('', $result->title);
        self::assertSame('', $result->url);
        self::assertSame(0.0, $result->score);
        self::assertNull($result->date);
    }

    #[Test]
    public function formatResultSnippetIsTruncatedTo200Chars(): void
    {
        $longContent = str_repeat('word ', 80);

        $result = $this->subject->formatResult(['content_t' => $longContent]);

        self::assertLessThanOrEqual(200, mb_strlen($result->snippet));
    }

    #[Test]
    public function formatResultSnippetStripsHtmlTags(): void
    {
        $result = $this->subject->formatResult(['content_t' => '<p>Hello <strong>world</strong></p>']);

        self::assertStringNotContainsString('<p>', $result->snippet);
        self::assertStringNotContainsString('<strong>', $result->snippet);
        self::assertStringContainsString('Hello', $result->snippet);
    }

    #[Test]
    public function buildContentReturnsEmptyStringForNonStdClassRecord(): void
    {
        $content = $this->invokeBuildContent(new \stdClass());

        self::assertIsString($content);
    }

    #[Test]
    public function buildContentIncludesDescriptionWhenPresent(): void
    {
        $record = new \stdClass();
        $record->description = 'A page about something interesting.';

        $content = $this->invokeBuildContent($record);

        self::assertStringContainsString('A page about something interesting.', $content);
    }

    #[Test]
    public function buildContentStripsHtmlFromDescription(): void
    {
        $record = new \stdClass();
        $record->description = '<p>Plain text <strong>here</strong>.</p>';

        $content = $this->invokeBuildContent($record);

        self::assertStringNotContainsString('<p>', $content);
        self::assertStringNotContainsString('<strong>', $content);
        self::assertStringContainsString('Plain text', $content);
    }

    #[Test]
    public function buildContentIncludesPageContentTextWhenProvided(): void
    {
        $record = new \stdClass();
        $content = $this->invokeBuildContent($record, 'Some heading Some body text');

        self::assertStringContainsString('Some heading Some body text', $content);
    }

    #[Test]
    public function buildContentCombinesDescriptionAndPageContentText(): void
    {
        $record = new \stdClass();
        $record->description = 'Page description here.';
        $content = $this->invokeBuildContent($record, 'Content heading body');

        self::assertStringContainsString('Page description here.', $content);
        self::assertStringContainsString('Content heading body', $content);
    }

    #[Test]
    public function buildContentReturnsEmptyStringForObjectWithoutProperties(): void
    {
        $content = $this->invokeBuildContent(new class {});

        self::assertSame('', $content);
    }

    #[Test]
    public function indexRecordSkipsNonStdClassRecord(): void
    {
        $context = new \Maispace\MaiSearch\Domain\Model\IndexingContext(core: 'core_de');

        $this->subject->indexRecord(new class {}, $context);

        self::assertTrue(true);
    }

    #[Test]
    public function formatResultReturnsNullRootlineWhenFieldIsMissing(): void
    {
        $result = $this->subject->formatResult([
            'title_s' => 'No Rootline',
            'content_t' => 'content',
            'url_s' => '/no-rootline',
        ]);

        self::assertNull($result->rootline);
    }

    #[Test]
    public function formatResultReturnsNullRootlineWhenFieldIsEmpty(): void
    {
        $result = $this->subject->formatResult([
            'title_s' => 'Empty Rootline',
            'content_t' => 'content',
            'url_s' => '/empty-rootline',
            'rootline_s' => '',
        ]);

        self::assertNull($result->rootline);
    }

    #[Test]
    public function formatResultParsesRootlineFromSolrDoc(): void
    {
        $result = $this->subject->formatResult([
            'title_s' => 'With Rootline',
            'content_t' => 'content',
            'url_s' => '/with-rootline',
            'rootline_s' => 'Home | About | Team',
        ]);

        self::assertNotNull($result->rootline);
        self::assertCount(3, $result->rootline);
        self::assertSame('Home', $result->rootline[0]);
        self::assertSame('About', $result->rootline[1]);
        self::assertSame('Team', $result->rootline[2]);
    }

    #[Test]
    public function formatResultParsesSingleItemRootline(): void
    {
        $result = $this->subject->formatResult([
            'title_s' => 'Root Page',
            'content_t' => 'content',
            'url_s' => '/',
            'rootline_s' => 'Home',
        ]);

        self::assertNotNull($result->rootline);
        self::assertCount(1, $result->rootline);
        self::assertSame('Home', $result->rootline[0]);
    }

    private function invokeBuildContent(object $record, string $pageContentText = ''): string
    {
        $reflection = new \ReflectionMethod($this->subject, 'buildContent');
        $reflection->setAccessible(true);

        /** @var string $result */
        return $reflection->invoke($this->subject, $record, $pageContentText);
    }
}
