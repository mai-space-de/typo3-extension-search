<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

/**
 * Prior turns of a RAG conversation, so the model can resolve follow-ups like
 * "and the second one?" (SPEC-v2.md Phase 7 addendum). Ported from
 * `.lookup/smart-search`'s `ConversationHistory` and adapted to this
 * extension's transport: since `mai_search` has no session state or frontend
 * JS runtime of its own, history travels round-trip as a hidden form field on
 * the stateless, full-page-reload search form (see SearchController) rather
 * than in a server-side session.
 *
 * Immutable — every mutator returns a new instance.
 */
final readonly class ConversationHistory implements \Countable
{
    private const array ALLOWED_ROLES = ['user', 'assistant'];

    /**
     * @param list<ChatMessage> $turns
     */
    private function __construct(private array $turns) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Deserialises a hidden-field payload. Deliberately tolerant rather than
     * throwing on a malformed/tampered entry (unlike withUserMessage()/
     * withAssistantMessage() below) — this is the one entry point parsing
     * data that arrived over an untrusted transport (a client-editable form
     * field), and a single corrupted turn must not break the search page for
     * that visitor. Invalid entries are skipped; a non-'user'/'assistant'
     * role is rejected the same way withMessage() enforces it internally.
     *
     * @param list<array{role?: mixed, content?: mixed}> $turns
     */
    public static function fromArray(array $turns): self
    {
        $messages = [];

        foreach ($turns as $turn) {
            $role = is_string($turn['role'] ?? null) ? $turn['role'] : '';
            $content = is_string($turn['content'] ?? null) ? $turn['content'] : '';

            if (!in_array($role, self::ALLOWED_ROLES, true) || $content === '') {
                continue;
            }

            $messages[] = new ChatMessage($role, $content);
        }

        return new self($messages);
    }

    /**
     * Deserialises a JSON-encoded hidden-field payload (see toJson()). Tolerant
     * the same way fromArray() is — a tampered or malformed value degrades to
     * empty history rather than breaking the request. Shared by SearchController
     * (hidden form field) and SearchStreamMiddleware (JSON request body), so
     * both entry points parse an untrusted client-supplied payload identically.
     */
    public static function fromJson(string $json): self
    {
        if ($json === '') {
            return self::empty();
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::empty();
        }

        return is_array($decoded) ? self::fromArray($decoded) : self::empty();
    }

    public function withUserMessage(string $content): self
    {
        return $this->withMessage('user', $content);
    }

    public function withAssistantMessage(string $content): self
    {
        return $this->withMessage('assistant', $content);
    }

    /**
     * Keeps the most recent $maxTurns messages, dropping the oldest — what a
     * context window running out actually needs, not an arbitrary cutoff.
     */
    public function truncated(int $maxTurns): self
    {
        if ($maxTurns < 0) {
            throw new \InvalidArgumentException('maxTurns must not be negative', 1754598000);
        }

        return new self($maxTurns === 0 ? [] : array_slice($this->turns, -$maxTurns));
    }

    /**
     * @return list<ChatMessage>
     */
    public function getMessages(): array
    {
        return $this->turns;
    }

    public function isEmpty(): bool
    {
        return $this->turns === [];
    }

    public function count(): int
    {
        return count($this->turns);
    }

    /**
     * Serialises back to the hidden-field payload shape fromArray() accepts.
     *
     * @return list<array{role: string, content: string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn(ChatMessage $message): array => ['role' => $message->role, 'content' => $message->content],
            $this->turns,
        );
    }

    /**
     * Serialises to the JSON string fromJson() accepts.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function withMessage(string $role, string $content): self
    {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported conversation role "%s"', $role), 1754598001);
        }

        if ($content === '') {
            return $this;
        }

        return new self([...$this->turns, new ChatMessage($role, $content)]);
    }
}
