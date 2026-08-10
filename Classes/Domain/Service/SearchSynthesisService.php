<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SourceReference;
use Maispace\MaiSearch\Domain\Dto\SynthesisResult;
use Maispace\MaiSearch\Domain\Model\ChatMessage;
use Maispace\MaiSearch\Domain\Model\ChatRequest;
use Maispace\MaiSearch\Domain\Model\ConversationHistory;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\ProviderRegistry;

/**
 * Synthesises a natural-language answer from top-ranked search context chunks.
 *
 * When RAG is enabled (see `plugin.tx_maisearch.settings.ragEnabled`), the top
 * search results are assembled into a prompt and sent to the active chat
 * provider (SPEC-v2.md Phase 2: OpenAI/Ollama/Custom via ProviderRegistry,
 * replacing the previous hardcoded direct OpenAI call). The returned answer is
 * rendered as an "answer card" above the hit list.
 *
 * Retrieval stays exactly as before — the top SearchResult[] from the primary
 * query are reused as context. Upgrading to per-type chunk-index retrieval
 * (mai_meilisearch's more precise design) is deferred to SPEC-v2.md Phase 7;
 * doing it here would have meant redesigning retrieval, not just swapping the
 * provider underneath it.
 *
 * Short-circuits (returns SynthesisResult::empty()) when:
 *  - $ragEnabled is false
 *  - The context chunk list is empty
 */
final class SearchSynthesisService
{
    private const string SYSTEM_PROMPT = 'You are a helpful assistant that answers questions based on the '
        . 'provided context. Keep your answer concise and factual. If the context does not contain enough '
        . 'information, say so briefly.';

    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly AnswerCacheService $answerCacheService,
        private readonly RagConfiguration $ragConfiguration,
    ) {}

    /**
     * @param string               $query      The user's original search query
     * @param SearchResult[]       $context    Top-ranked search results to use as context
     * @param bool                 $ragEnabled Whether RAG synthesis is enabled
     * @param ConversationHistory|null $history Prior turns, inserted between the system
     *                                          message and the current question so the
     *                                          model can resolve follow-ups ("and in German?")
     */
    public function synthesise(
        string $query,
        array $context,
        bool $ragEnabled = true,
        ?ConversationHistory $history = null,
    ): SynthesisResult {
        if (!$ragEnabled) {
            return SynthesisResult::empty();
        }

        if ($context === []) {
            return SynthesisResult::empty();
        }

        $sources = $this->buildSources($context);

        $cachedAnswer = $this->getCachedAnswer($query, $context, $history);
        if ($cachedAnswer !== null) {
            return new SynthesisResult($cachedAnswer, $sources);
        }

        $request = $this->buildChatRequest($query, $context, $history);
        $answer = $this->providerRegistry->getActiveChatProvider()->complete($request)->content;

        $this->cacheAnswer($query, $context, $history, $answer);

        return new SynthesisResult($answer, $sources);
    }

    /**
     * Checks the answer cache without performing synthesis. Used by
     * SearchStreamMiddleware (SPEC-v3-caching-chunking.md Phase C) so a repeated
     * question is served as a fast SSE burst of the cached answer instead of a live,
     * token-by-token chat-completion call — same cache-key semantics as synthesise()
     * itself, since both build the key from query + context URLs + history.
     *
     * @param SearchResult[] $context
     */
    public function getCachedAnswer(string $query, array $context, ?ConversationHistory $history = null): ?string
    {
        return $this->answerCacheService->get($query, $this->contextUrls($context), $history?->toArray() ?? []);
    }

    /**
     * Stores an answer produced outside synthesise() — the streaming path generates
     * its answer token-by-token via StreamingChatProviderInterface::stream() rather
     * than complete(), so it can't go through synthesise() itself, but must still be
     * cached with identical key semantics so a later synchronous/streaming request
     * for the same question hits the same cache entry.
     *
     * @param SearchResult[] $context
     */
    public function cacheAnswer(string $query, array $context, ?ConversationHistory $history, string $answer): void
    {
        $this->answerCacheService->set(
            $query,
            $this->contextUrls($context),
            $history?->toArray() ?? [],
            $answer,
            $this->ragConfiguration->getAnswerCacheTtl(),
        );
    }

    /**
     * Builds the same ChatRequest synthesise() sends to the active chat provider,
     * exposed separately so the streaming endpoint (Middleware/SearchStreamMiddleware)
     * can send an identical prompt through StreamingChatProviderInterface::stream()
     * instead of ChatProviderInterface::complete() — both paths must produce the same
     * answer for the same input, just delivered synchronously vs. token-by-token.
     *
     * @param SearchResult[] $context
     */
    public function buildChatRequest(string $query, array $context, ?ConversationHistory $history = null): ChatRequest
    {
        $messages = [new ChatMessage('system', self::SYSTEM_PROMPT)];

        if ($history !== null) {
            array_push($messages, ...$history->getMessages());
        }

        $messages[] = new ChatMessage('user', $this->buildUserPrompt($query, $context));

        return new ChatRequest($messages);
    }

    /**
     * @param SearchResult[] $context
     * @return SourceReference[]
     */
    private function buildSources(array $context): array
    {
        $sources = [];
        foreach ($context as $result) {
            $sources[] = new SourceReference(
                title: $result->title,
                url: $result->url,
                type: $result->type,
                score: $result->score,
            );
        }

        return $sources;
    }

    /**
     * @param SearchResult[] $context
     * @return list<string>
     */
    private function contextUrls(array $context): array
    {
        return array_map(static fn(SearchResult $result): string => $result->url, $context);
    }

    /**
     * @param SearchResult[] $context
     */
    private function buildUserPrompt(string $query, array $context): string
    {
        $chunks = [];
        foreach ($context as $index => $result) {
            $number = $index + 1;
            $source = $result->title !== '' ? $result->title : $result->url;
            $text = $result->snippet !== '' ? $result->snippet : $result->title;
            $chunks[] = sprintf('[%d] %s: %s', $number, $source, $text);
        }

        return sprintf(
            "Based on the following context, answer the question concisely.\n\nContext:\n%s\n\nQuestion: %s",
            implode("\n", $chunks),
            $query,
        );
    }
}
