<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Controller\Backend;

use Maispace\MaiBase\Controller\Backend\AbstractBackendController;
use Maispace\MaiBase\Controller\Traits\ResponseHelpersTrait;
use Maispace\MaiSearch\Configuration\RagPromptFactory;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\IndexQueueProcessor;
use Maispace\MaiSearch\Domain\Service\ProvisioningService;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsController]
class SearchBackendController extends AbstractBackendController
{
    use ResponseHelpersTrait;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IconFactory $iconFactory,
        private readonly IndexManagementService $indexManagementService,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ProvisioningService $provisioningService,
        private readonly IndexQueueRepository $indexQueueRepository,
        private readonly IndexQueueProcessor $indexQueueProcessor,
        private readonly AnswerCacheService $answerCacheService,
        private readonly RagPromptFactory $ragPromptFactory,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct($moduleTemplateFactory, $iconFactory);
    }

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate();
        $this->addShortcutButton(
            $moduleTemplate,
            'mai_search',
            'Search Index',
        );

        $coreMapping = $this->indexManagementService->getCoreMapping();
        $indexers = $this->indexerRegistry->getAll();

        $indexerTypes = [];
        foreach ($indexers as $indexer) {
            $indexerTypes[] = $indexer->getType();
        }

        $indexStats = $this->indexManagementService->getIndexStats();
        $queueProgress = $this->buildQueueProgress();
        $site = $this->resolveSite();
        $ragPrompt = $this->ragPromptFactory->fromSite($site);

        $this->assignMultiple($moduleTemplate, [
            'coreMapping' => $coreMapping,
            'coreCount' => $coreMapping === [] ? 1 : count($coreMapping),
            'indexerTypes' => $indexerTypes,
            'indexerCount' => count($indexerTypes),
            'indexStats' => $indexStats,
            'queueProgress' => $queueProgress,
            'queueStatusUrl' => $this->uriBuilder->reset()->uriFor('queueStatus'),
            'ragPrompt' => $ragPrompt,
            'ragPromptIsDefault' => $ragPrompt->isDefault(),
            'ragPromptSiteResolved' => $site !== null,
        ]);

        return $this->renderModuleResponse($moduleTemplate, 'Index');
    }

    public function reindexAction(): ResponseInterface
    {
        try {
            $coreMapping = $this->indexManagementService->getCoreMapping();
            $indexers = $this->indexerRegistry->getAll();

            $enqueued = $this->indexManagementService->repopulateQueue($this->indexQueueRepository);

            $this->flashSuccess(
                sprintf(
                    'Enqueued %d record(s) for re-indexing across %d core(s) with %d indexer(s). Queue will be processed by scheduler.',
                    $enqueued,
                    $coreMapping === [] ? 1 : count($coreMapping),
                    count($indexers),
                ),
            );
        } catch (\Throwable $e) {
            $this->flashError(
                sprintf('Re-index failed: %s', $e->getMessage()),
            );
        }

        return $this->redirect('index');
    }

    public function clearAction(): ResponseInterface
    {
        try {
            $this->indexManagementService->clearAllCores();

            $this->flashSuccess('Search index has been cleared.');
        } catch (\Throwable $e) {
            $this->flashError(
                sprintf('Failed to clear index: %s', $e->getMessage()),
            );
        }

        return $this->redirect('index');
    }

    /**
     * SPEC-v3-caching-chunking.md Phase C (gap G6): lets an admin drop a stale or
     * incorrect RAG answer from the cache without rebuilding the whole search index.
     */
    public function clearAnswerCacheAction(): ResponseInterface
    {
        try {
            $this->answerCacheService->invalidateAll();

            $this->flashSuccess('RAG answer cache has been cleared.');
        } catch (\Throwable $e) {
            $this->flashError(
                sprintf('Failed to clear RAG answer cache: %s', $e->getMessage()),
            );
        }

        return $this->redirect('index');
    }

    public function provisionAction(): ResponseInterface
    {
        try {
            $result = $this->provisioningService->provision();

            if ($result->message !== null) {
                $this->flashSuccess($result->message);
            } elseif ($result->provisioned === []) {
                $this->flashSuccess(sprintf('%s: everything already exists, nothing to do.', $result->backend));
            } else {
                $this->flashSuccess(
                    sprintf(
                        '%s: provisioned %d item(s): %s',
                        $result->backend,
                        count($result->provisioned),
                        implode(', ', $result->provisioned),
                    ),
                );
            }
        } catch (\Throwable $e) {
            $this->flashError(
                sprintf('Provisioning failed: %s', $e->getMessage()),
            );
        }

        return $this->redirect('index');
    }

    public function queueAction(): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate();
        $this->addShortcutButton(
            $moduleTemplate,
            'mai_search',
            'Search Index Queue',
        );

        $queueProgress = $this->buildQueueProgress();

        $this->assignMultiple($moduleTemplate, [
            'counts' => $queueProgress['counts'],
            'queueProgress' => $queueProgress,
            'jobs' => $this->formatJobsForDisplay($this->indexQueueRepository->findRecent(50)),
            'queueStatusUrl' => $this->uriBuilder->reset()->uriFor('queueStatus'),
        ]);

        return $this->renderModuleResponse($moduleTemplate, 'Queue');
    }

    public function queueStatusAction(): ResponseInterface
    {
        $queueProgress = $this->buildQueueProgress();

        return $this->dataAsJsonResponse([
            'counts' => $queueProgress['counts'],
            'total' => $queueProgress['total'],
            'percentages' => $queueProgress['percentages'],
            'jobs' => $this->formatJobsForDisplay($this->indexQueueRepository->findRecent(50)),
        ]);
    }

    public function processQueueAction(): ResponseInterface
    {
        try {
            $processed = $this->indexQueueProcessor->processBatch();

            if ($processed > 0) {
                $this->flashSuccess(sprintf('Processed %d queue item(s).', $processed));
            } else {
                $this->flashSuccess('Queue is empty, nothing to process.');
            }
        } catch (\Throwable $e) {
            $this->flashError(
                sprintf('Queue processing failed: %s', $e->getMessage()),
            );
        }

        return $this->redirect('queue');
    }

    /**
     * @return array{
     *     counts: array{pending: int, processing: int, done: int, failed: int},
     *     total: int,
     *     percentages: array{pending: float, processing: float, done: float, failed: float}
     * }
     */
    private function buildQueueProgress(): array
    {
        $counts = $this->indexQueueRepository->countByStatus();
        $total = (int) array_sum($counts);
        $percentages = [];

        foreach ($counts as $status => $count) {
            $percentages[$status] = $total > 0
                ? round(((int) $count / $total) * 100, 1)
                : 0.0;
        }

        return [
            'counts' => $counts,
            'total' => $total,
            'percentages' => $percentages,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     * @return list<array{uid: int, tableName: string, recordUid: int, operation: string, status: string, errorMessage: string, queuedDate: string, processedDate: string}>
     */
    private function formatJobsForDisplay(array $jobs): array
    {
        return array_map(
            static fn(array $job): array => [
                'uid' => (int) $job['uid'],
                'tableName' => (string) $job['table_name'],
                'recordUid' => (int) $job['record_uid'],
                'operation' => (string) $job['operation'],
                'status' => (string) $job['status'],
                'errorMessage' => (string) $job['error_message'],
                'queuedDate' => (int) $job['queued_date'] > 0 ? date('Y-m-d H:i:s', (int) $job['queued_date']) : '',
                'processedDate' => (int) $job['processed_date'] > 0 ? date('Y-m-d H:i:s', (int) $job['processed_date']) : '',
            ],
            $jobs,
        );
    }

    private function resolveSite(): ?Site
    {
        $site = $this->request->getAttribute('site');
        if ($site instanceof Site) {
            return $site;
        }

        foreach ($this->siteFinder->getAllSites() as $candidate) {
            return $candidate;
        }

        return null;
    }
}
