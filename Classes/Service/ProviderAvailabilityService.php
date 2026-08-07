<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Reports whether the currently active embedding/chat provider can actually be
 * reached, so a consumer (e.g. the frontend RAG toggle) can hide AI-dependent
 * features rather than let them fail in front of a visitor. Ported idea from
 * `.lookup/smart-search`'s ModelAvailabilityService (SPEC-v2.md Phase 7 addendum);
 * adapted to mai_search's three provider identifiers (openai/ollama/custom)
 * instead of smart_search's llamacpp/ollama/openai set.
 *
 * Follows whichever provider is actually configured per concern (embedding vs.
 * chat) rather than probing a fixed URL — probing the wrong backend reports a
 * working install as unavailable and hides exactly the feature it should guard.
 *
 * OpenAI has no free health endpoint; a billable API call is not something to
 * issue on a page render, so availability is reported by whether an API key is
 * configured, with no network call. The "custom" provider is a user-supplied
 * REST template with no known health-check shape, so it is reported available
 * whenever an endpoint URL is configured, again with no network call.
 *
 * Probes (Ollama only) use a 2-second timeout and never throw. Results are
 * cached for the lifetime of this instance (one request).
 */
class ProviderAvailabilityService implements LoggerAwareInterface, SingletonInterface
{
    use LoggerAwareTrait;

    private ?bool $embeddingAvailable = null;

    private ?bool $chatAvailable = null;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ProviderFactory $providerFactory,
    ) {}

    public function isEmbeddingProviderAvailable(): bool
    {
        return $this->embeddingAvailable ??= $this->checkProvider(
            $this->providerFactory->getActiveEmbeddingProviderIdentifier(),
            'embedding',
        );
    }

    public function isChatProviderAvailable(): bool
    {
        return $this->chatAvailable ??= $this->checkProvider(
            $this->providerFactory->getActiveChatProviderIdentifier(),
            'chat',
        );
    }

    private function checkProvider(string $identifier, string $kind): bool
    {
        return match ($identifier) {
            'openai' => $this->hasOpenAiKey($kind),
            'ollama' => $this->checkUrl(rtrim($this->providerFactory->getOllamaBaseUrl(), '/') . '/api/tags', $kind),
            'custom' => $this->hasCustomUrl($kind),
            default => false,
        };
    }

    private function hasOpenAiKey(string $kind): bool
    {
        if ($this->providerFactory->getOpenAiApiKey() !== '') {
            return true;
        }

        $this->logger?->debug('mai_search {kind} provider is OpenAI but no API key is configured', ['kind' => $kind]);

        return false;
    }

    private function hasCustomUrl(string $kind): bool
    {
        $url = $kind === 'embedding'
            ? $this->providerFactory->getCustomEmbeddingUrl()
            : $this->providerFactory->getCustomChatUrl();

        if ($url !== '') {
            return true;
        }

        $this->logger?->debug('mai_search {kind} provider is custom but no endpoint URL is configured', ['kind' => $kind]);

        return false;
    }

    private function checkUrl(string $url, string $kind): bool
    {
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => 2,
                'http_errors' => false,
            ]);

            $available = $response->getStatusCode() < 300;

            if (!$available) {
                $this->logger?->debug('mai_search {kind} provider health check failed', [
                    'kind' => $kind,
                    'url' => $url,
                    'status_code' => $response->getStatusCode(),
                ]);
            }

            return $available;
        } catch (\Throwable $e) {
            $this->logger?->debug('mai_search {kind} provider is unreachable', [
                'kind' => $kind,
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
