<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Model;

use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SearchDocumentInputTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $crdate = new \DateTimeImmutable('2026-05-23');
        $rootline = ['Home', 'News'];
        $additionalFields = ['author_s' => 'Jane Doe'];

        $document = new SearchDocumentInput(
            type: 'news',
            uid: 42,
            title: 'Test Title',
            content: 'Test content body',
            url: '/news/test-title',
            crdate: $crdate,
            boost: 2.0,
            rootline: $rootline,
            additionalFields: $additionalFields,
        );

        self::assertSame('news', $document->type);
        self::assertSame(42, $document->uid);
        self::assertSame('Test Title', $document->title);
        self::assertSame('Test content body', $document->content);
        self::assertSame('/news/test-title', $document->url);
        self::assertSame($crdate, $document->crdate);
        self::assertSame(2.0, $document->boost);
        self::assertSame($rootline, $document->rootline);
        self::assertSame($additionalFields, $document->additionalFields);
    }

    #[Test]
    public function defaultsRootlineAndAdditionalFieldsToEmptyArray(): void
    {
        $document = new SearchDocumentInput(
            type: 'page',
            uid: 1,
            title: 'Rootless',
            content: '',
            url: '/rootless',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
        );

        self::assertSame([], $document->rootline);
        self::assertSame([], $document->additionalFields);
    }

    #[Test]
    public function withAdditionalFieldReturnsNewInstanceWithMergedField(): void
    {
        $original = new SearchDocumentInput(
            type: 'news',
            uid: 42,
            title: 'Test Title',
            content: 'Test content body',
            url: '/news/test-title',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
            additionalFields: ['author_s' => 'Jane Doe'],
        );

        $withLanguage = $original->withAdditionalField('languageCode', 'de');

        self::assertNotSame($original, $withLanguage);
        self::assertSame(['author_s' => 'Jane Doe'], $original->additionalFields);
        self::assertSame(['author_s' => 'Jane Doe', 'languageCode' => 'de'], $withLanguage->additionalFields);
    }

    #[Test]
    public function withAdditionalFieldOverwritesExistingKey(): void
    {
        $original = new SearchDocumentInput(
            type: 'news',
            uid: 42,
            title: 'Test Title',
            content: 'Test content body',
            url: '/news/test-title',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
            additionalFields: ['languageCode' => 'en'],
        );

        $updated = $original->withAdditionalField('languageCode', 'de');

        self::assertSame(['languageCode' => 'de'], $updated->additionalFields);
    }
}
