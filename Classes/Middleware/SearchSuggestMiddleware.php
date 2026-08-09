<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Middleware;

use Maispace\MaiBase\Middleware\Api\AbstractApiMiddleware;
use Maispace\MaiSearch\Configuration\SuggestConfiguration;
use Maispace\MaiSearch\Suggest\SuggestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Live search suggestions/autocomplete (SPEC-v2.md Phase 8) over a plain JSON
 * GET endpoint.
 *
 * A plain PSR-15 middleware rather than an Extbase action for the same reason
 * SearchStreamMiddleware is one — tested first as a normal `suggestAction()`
 * returning a JsonResponse, and confirmed broken: TYPO3's page rendering/cache
 * pipeline wrapped the JSON body in the full cached HTML page while only the
 * JsonResponse's headers (Content-Type: application/json) leaked through, since
 * an Extbase content-element action doesn't replace the top-level page response
 * the way a middleware does. Registered before any Extbase/TypoScript frontend
 * context is resolved, so settings come from SuggestConfiguration (Extension
 * Configuration), not TypoScript plugin settings.
 */
class SearchSuggestMiddleware extends AbstractApiMiddleware
{
    private const API_PATH = '/api/search/suggest';

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        private readonly SuggestConfiguration $suggestConfiguration,
        private readonly ?SuggestInterface $suggestService = null,
    ) {
        parent::__construct($responseFactory, $streamFactory);
    }

    public function shouldHandle(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getPath() === self::API_PATH && $this->isMethod($request, 'GET');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->suggestConfiguration->isEnabled() || $this->suggestService === null) {
            return $this->jsonResponse(['suggestions' => []], 404);
        }

        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));

        if (mb_strlen($query) < $this->suggestConfiguration->getMinChars()) {
            return $this->jsonResponse(['suggestions' => []]);
        }

        $suggestions = $this->suggestService->getSuggestions(
            $query,
            $this->suggestConfiguration->getLimit(),
            $this->resolveLanguage($request),
        );

        return $this->jsonResponse(['suggestions' => $suggestions]);
    }

    private function resolveLanguage(ServerRequestInterface $request): ?SiteLanguage
    {
        $language = $request->getAttribute('language');

        return $language instanceof SiteLanguage ? $language : null;
    }
}
