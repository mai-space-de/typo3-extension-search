<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Configuration;

use Maispace\MaiSearch\Domain\Model\RagPrompt;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Resolves RAG prompts from Site Settings (`search.rag.systemPrompt` /
 * `search.rag.userTemplate`, see Configuration/Sets/MaiSearch).
 *
 * Empty values and user templates that omit `{context}` or `{query}` fall
 * back to RagPrompt::defaults() so a broken override cannot empty the
 * synthesis prompt. SearchStreamMiddleware reads the same Site attribute as
 * the frontend controller — Site Settings are available there, unlike
 * TypoScript plugin settings (see RagConfiguration).
 */
final class RagPromptFactory
{
    public const string SETTING_SYSTEM_PROMPT = 'search.rag.systemPrompt';
    public const string SETTING_USER_TEMPLATE = 'search.rag.userTemplate';

    public function fromSite(?Site $site): RagPrompt
    {
        if ($site === null) {
            return RagPrompt::defaults();
        }

        $settings = $site->getSettings();
        $system = trim((string) $settings->get(self::SETTING_SYSTEM_PROMPT, ''));
        $user = trim((string) $settings->get(self::SETTING_USER_TEMPLATE, ''));
        $defaults = RagPrompt::defaults();

        return new RagPrompt(
            systemPrompt: $system !== '' ? $system : $defaults->systemPrompt,
            userTemplate: self::isValidUserTemplate($user) ? $user : $defaults->userTemplate,
        );
    }

    public static function isValidUserTemplate(string $template): bool
    {
        return str_contains($template, '{context}') && str_contains($template, '{query}');
    }
}
