<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Synthesises a natural-language answer from top-ranked search context chunks.
 *
 * When RAG is enabled (see `plugin.tx_maisearch.settings.ragEnabled`) and an
 * OpenAI API key is configured, the top search results are assembled into a
 * system prompt and sent to the OpenAI Chat Completions API (`gpt-4o-mini`).
 * The returned answer is rendered as an "answer card" above the hit list.
 *
 * Short-circuits (returns '') when:
 *  - $ragEnabled is false
 *  - The context chunk list is empty
 *  - The API key is empty
 */
final class SearchSynthesisService
{
    private const string API_URL = 'https://api.openai.com/v1/chat/completions';
    private const int ERROR_CODE = 1748200001;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4o-mini',
    ) {}

    /**
     * Synthesise an answer from the given search context.
     *
     * @param string         $query     The user's original search query
     * @param SearchResult[] $context   Top-ranked search results to use as context chunks
     * @param bool           $ragEnabled Whether RAG synthesis is enabled
     *
     * @return string Synthesised answer, or '' when synthesis is skipped or fails
     */
    public function synthesise(string $query, array $context, bool $ragEnabled = true): string
    {
        if (!$ragEnabled) {
            return '';
        }

        if ($context === []) {
            return '';
        }

        if ($this->apiKey === '') {
            return '';
        }

        $prompt = $this->buildUserPrompt($query, $context);

        return $this->callApi($prompt);
    }

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
}
