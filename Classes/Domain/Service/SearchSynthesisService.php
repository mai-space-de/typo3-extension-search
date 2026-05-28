<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SourceReference;
use Maispace\MaiSearch\Domain\Dto\SynthesisResult;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Synthesises a natural-language answer from top-ranked search context chunks.
 *
 * When RAG is enabled (see `plugin.tx_maisearch.settings.ragEnabled`) and an
 * OpenAI API key is configured, the top search results are assembled into a
 * system prompt and sent to the OpenAI Chat Completions API (`gpt-4o-mini`).
 * The returned answer is rendered as an "answer card" above the hit list.
 *
 * Short-circuits (returns SynthesisResult::empty()) when:
 *  - $ragEnabled is false
 *  - The context chunk list is empty
 *  - The API key is empty
 */
final class SearchSynthesisService
{
    private const string API_URL = 'https://api.openai.com/v1/chat/completions';
    private const int ERROR_CODE = 1748200001;

    private readonly string $apiKey;
    private readonly string $model;

    /**
     * @param string|null $apiKey OpenAI API key. When null, falls back to
     *                            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_translate']['openAiApiKey']
     * @param string|null $model  OpenAI model name. When null, falls back to
     *                            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_translate']['openAiModel']
     */
    public function __construct(
        private readonly RequestFactory $requestFactory,
        ?string $apiKey = null,
        ?string $model = null,
    ) {
        $this->apiKey = $apiKey ?? $this->resolveApiKey();
        $this->model = $model ?? $this->resolveModel();
    }

    /**
     * @param string         $query      The user's original search query
     * @param SearchResult[] $context    Top-ranked search results to use as context
     * @param bool           $ragEnabled Whether RAG synthesis is enabled
     */
    public function synthesise(string $query, array $context, bool $ragEnabled = true): SynthesisResult
    {
        if (!$ragEnabled) {
            return SynthesisResult::empty();
        }

        if ($context === []) {
            return SynthesisResult::empty();
        }

        if ($this->apiKey === '') {
            return SynthesisResult::empty();
        }

        $prompt = $this->buildUserPrompt($query, $context);
        $answer = $this->callApi($prompt);
        $sources = $this->buildSources($context);

        return new SynthesisResult($answer, $sources);
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

    private function callApi(string $userPrompt): string
    {
        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful assistant that answers questions based on the provided context. '
                        . 'Keep your answer concise and factual. '
                        . 'If the context does not contain enough information, say so briefly.',
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
        ];

        $response = $this->requestFactory->request(self::API_URL, 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($statusCode !== 200) {
            throw new \RuntimeException(
                sprintf('OpenAI API error (HTTP %d): %s', $statusCode, $body),
                self::ERROR_CODE,
            );
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return (string) ($data['choices'][0]['message']['content'] ?? '');
    }

    private function resolveApiKey(): string
    {
        return (string) ($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_translate']['openAiApiKey'] ?? '');
    }

    private function resolveModel(): string
    {
        return (string) ($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_translate']['openAiModel'] ?? 'gpt-4o-mini');
    }
}
