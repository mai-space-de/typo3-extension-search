<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

/**
 * Resolved RAG synthesis prompts. Authoritative defaults live here;
 * `search.rag.userTemplate` site setting defaults to empty so multiline
 * `{context}` / `{query}` placeholders are not injected into TypoScript
 * constants. Empty or invalid site settings fall back to these constants.
 *
 * `{context}` / `{query}` in the user template are replaced by
 * SearchSynthesisService when assembling the chat request. The fingerprint is
 * part of the answer-cache key so a prompt edit cannot serve a stale answer.
 */
final readonly class RagPrompt
{
    public const string DEFAULT_SYSTEM_PROMPT = 'You are a helpful assistant that answers questions based on the '
        . 'provided context. Keep your answer concise and factual. Answer in the same language as the question. '
        . 'If the context does not contain enough information, say so briefly.';

    public const string DEFAULT_USER_TEMPLATE = "Based on the following context, answer the question concisely.\n\n"
        . "Context:\n{context}\n\nQuestion: {query}";

    public function __construct(
        public string $systemPrompt,
        public string $userTemplate,
    ) {}

    public static function defaults(): self
    {
        return new self(self::DEFAULT_SYSTEM_PROMPT, self::DEFAULT_USER_TEMPLATE);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->systemPrompt . "\0" . $this->userTemplate);
    }

    public function renderUserPrompt(string $query, string $contextBlock): string
    {
        return str_replace(
            ['{context}', '{query}'],
            [$contextBlock, $query],
            $this->userTemplate,
        );
    }

    public function isDefault(): bool
    {
        return $this->systemPrompt === self::DEFAULT_SYSTEM_PROMPT
            && $this->userTemplate === self::DEFAULT_USER_TEMPLATE;
    }
}
