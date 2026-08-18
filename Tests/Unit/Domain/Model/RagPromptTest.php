<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Model;

use Maispace\MaiSearch\Domain\Model\RagPrompt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RagPromptTest extends TestCase
{
    #[Test]
    public function defaultsExposeTheDocumentedSystemPromptAndUserTemplate(): void
    {
        $prompt = RagPrompt::defaults();

        self::assertSame(RagPrompt::DEFAULT_SYSTEM_PROMPT, $prompt->systemPrompt);
        self::assertSame(RagPrompt::DEFAULT_USER_TEMPLATE, $prompt->userTemplate);
        self::assertTrue($prompt->isDefault());
    }

    #[Test]
    public function renderUserPromptReplacesPlaceholders(): void
    {
        $prompt = new RagPrompt('sys', "Q: {query}\nC: {context}");

        self::assertSame("Q: opening hours\nC: [1] Page: snippet", $prompt->renderUserPrompt(
            'opening hours',
            '[1] Page: snippet',
        ));
        self::assertFalse($prompt->isDefault());
    }

    #[Test]
    public function fingerprintChangesWhenEitherPromptChanges(): void
    {
        $base = new RagPrompt('sys', 'Q: {query} C: {context}');
        $otherSystem = new RagPrompt('other', 'Q: {query} C: {context}');
        $otherUser = new RagPrompt('sys', 'Question {query}\n{context}');

        self::assertNotSame($base->fingerprint(), $otherSystem->fingerprint());
        self::assertNotSame($base->fingerprint(), $otherUser->fingerprint());
        self::assertSame($base->fingerprint(), (new RagPrompt('sys', 'Q: {query} C: {context}'))->fingerprint());
    }
}
