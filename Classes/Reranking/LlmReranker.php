<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Reranking;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Model\ChatMessage;
use Maispace\MaiSearch\Domain\Model\ChatRequest;
use Maispace\MaiSearch\Service\ProviderRegistry;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Reorders candidates with a single chat-completion call: the model sees each
 * candidate's title and snippet (not the full document — keeps the prompt
 * small and avoids leaking unrelated result content into a single request)
 * and returns a relevance-ordered list of candidate numbers.
 *
 * Improves on `.lookup/smart-search`'s reference `LlmReranker`, which sends
 * only opaque identifiers and therefore orders candidates the model has never
 * actually seen — `mai_search` has real title/snippet text available, so this
 * gives the model something to judge relevance against.
 *
 * Degrades gracefully: any provider failure (unconfigured, unreachable,
 * malformed response) falls back to the original retrieval order, truncated
 * to $topN, rather than failing the search.
 */
class LlmReranker implements RerankerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
    ) {}

    public function rerank(string $query, array $candidates, int $topN): array
    {
        $candidates = array_values($candidates);

        if ($candidates === []) {
            return [];
        }

        if (count($candidates) < $topN) {
            return $candidates;
        }

        $order = $this->requestOrder($query, $candidates);
        $reordered = [];
        $seen = [];

        foreach ($order as $index) {
            if (isset($candidates[$index]) && !isset($seen[$index])) {
                $reordered[] = $candidates[$index];
                $seen[$index] = true;
            }
        }

        // The model may omit candidates (context limit, hallucinated numbering, or a
        // failed/unparseable request, where $order is simply empty) — append the rest
        // in their original order rather than silently dropping them.
        foreach ($candidates as $index => $candidate) {
            if (!isset($seen[$index])) {
                $reordered[] = $candidate;
            }
        }

        return array_slice($reordered, 0, $topN);
    }

    /**
     * @param list<SearchResult> $candidates
     * @return list<int>
     */
    private function requestOrder(string $query, array $candidates): array
    {
        try {
            $response = $this->providerRegistry->getActiveChatProvider()->complete(
                new ChatRequest([
                    new ChatMessage(
                        'system',
                        'You rank search results by relevance. Respond with only a JSON array of the '
                            . 'candidate numbers, most relevant first. No other text.',
                    ),
                    new ChatMessage('user', $this->buildPrompt($query, $candidates)),
                ]),
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('LlmReranker request failed, falling back to original order: {message}', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        return $this->parseOrder($response->content);
    }

    /**
     * @param list<SearchResult> $candidates
     */
    private function buildPrompt(string $query, array $candidates): string
    {
        $lines = [];
        foreach ($candidates as $index => $candidate) {
            $lines[] = sprintf('[%d] %s: %s', $index, $candidate->title, $candidate->snippet);
        }

        return sprintf("Query: %s\n\nCandidates:\n%s", $query, implode("\n", $lines));
    }

    /**
     * @return list<int>
     */
    private function parseOrder(string $content): array
    {
        if (preg_match('/\[[\d,\s]*]/', $content, $matches) !== 1) {
            $this->logger?->warning('LlmReranker response did not contain a JSON array: {content}', [
                'content' => $content,
            ]);

            return [];
        }

        $decoded = json_decode($matches[0], true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('intval', $decoded));
    }
}
