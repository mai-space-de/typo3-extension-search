<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Configuration;

use Maispace\MaiSearch\Configuration\RagPromptFactory;
use Maispace\MaiSearch\Domain\Model\RagPrompt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;

final class RagPromptFactoryTest extends TestCase
{
    private RagPromptFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RagPromptFactory();
    }

    #[Test]
    public function fromSiteReturnsDefaultsWhenSiteIsNull(): void
    {
        $prompt = $this->factory->fromSite(null);

        self::assertTrue($prompt->isDefault());
    }

    #[Test]
    public function fromSiteReturnsDefaultsWhenSettingsAreEmpty(): void
    {
        $prompt = $this->factory->fromSite($this->siteWith([]));

        self::assertTrue($prompt->isDefault());
    }

    #[Test]
    public function fromSiteUsesConfiguredPrompts(): void
    {
        $prompt = $this->factory->fromSite($this->siteWith([
            RagPromptFactory::SETTING_SYSTEM_PROMPT => 'Custom system',
            RagPromptFactory::SETTING_USER_TEMPLATE => "Ask: {query}\nSources:\n{context}",
        ]));

        self::assertSame('Custom system', $prompt->systemPrompt);
        self::assertSame("Ask: {query}\nSources:\n{context}", $prompt->userTemplate);
        self::assertFalse($prompt->isDefault());
    }

    #[Test]
    public function fromSiteFallsBackWhenUserTemplateOmitsPlaceholders(): void
    {
        $prompt = $this->factory->fromSite($this->siteWith([
            RagPromptFactory::SETTING_SYSTEM_PROMPT => 'Custom system',
            RagPromptFactory::SETTING_USER_TEMPLATE => 'broken template without placeholders',
        ]));

        self::assertSame('Custom system', $prompt->systemPrompt);
        self::assertSame(RagPrompt::DEFAULT_USER_TEMPLATE, $prompt->userTemplate);
    }

    #[Test]
    public function fromSiteTreatsWhitespaceOnlyAsEmpty(): void
    {
        $prompt = $this->factory->fromSite($this->siteWith([
            RagPromptFactory::SETTING_SYSTEM_PROMPT => '   ',
            RagPromptFactory::SETTING_USER_TEMPLATE => "\n",
        ]));

        self::assertTrue($prompt->isDefault());
    }

    #[Test]
    public function isValidUserTemplateRequiresBothPlaceholders(): void
    {
        self::assertTrue(RagPromptFactory::isValidUserTemplate('{query} and {context}'));
        self::assertFalse(RagPromptFactory::isValidUserTemplate('{query} only'));
        self::assertFalse(RagPromptFactory::isValidUserTemplate('{context} only'));
        self::assertFalse(RagPromptFactory::isValidUserTemplate(''));
    }

    /**
     * @param array<string, string> $settings
     */
    private function siteWith(array $settings): Site
    {
        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn(SiteSettings::create(new Settings($settings)));

        return $site;
    }
}
