<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Service\BackendRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

class SearchServiceTest extends TestCase
{
    private BackendRegistry $backendRegistry;
    private SearchService $searchService;

    protected function setUp(): void
    {
        $this->backendRegistry = $this->createMock(BackendRegistry::class);
        $this->searchService = new SearchService($this->backendRegistry);
    }

    #[Test]
    public function searchBuildsSearchRequestAndDelegatesToActiveBackend(): void
    {
        $language = $this->createMock(SiteLanguage::class);
        $expectedPage = new SearchResultPage([], 0);

        $backend = $this->createMock(SearchBackendInterface::class);
        $backend
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchRequest $request) use ($language): bool {
                return $request->query === 'gymnastics'
                    && $request->limit === 10
                    && $request->offset === 5
                    && $request->language === $language
                    && $request->type === 'news'
                    && $request->ragEnabled === true;
            }))
            ->willReturn($expectedPage);

        $this->backendRegistry->method('getActive')->willReturn($backend);

        $result = $this->searchService->search(
            'gymnastics',
            10,
            5,
            $language,
            ragEnabled: true,
            type: 'news',
        );

        self::assertSame($expectedPage, $result);
    }

    #[Test]
    public function searchUsesDefaultValues(): void
    {
        $backend = $this->createMock(SearchBackendInterface::class);
        $backend
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchRequest $request): bool {
                return $request->limit === 20
                    && $request->offset === 0
                    && $request->language === null
                    && $request->type === null
                    && $request->ragEnabled === false;
            }))
            ->willReturn(new SearchResultPage([], 0));

        $this->backendRegistry->method('getActive')->willReturn($backend);

        $this->searchService->search('gymnastics');
    }
}
