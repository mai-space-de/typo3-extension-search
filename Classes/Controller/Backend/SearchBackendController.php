<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Controller\Backend;

use Maispace\MaiBase\Controller\Backend\AbstractBackendController;
use Maispace\MaiBase\Controller\Traits\ResponseHelpersTrait;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;

#[AsController]
class SearchBackendController extends AbstractBackendController
{
    use ResponseHelpersTrait;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        IconFactory $iconFactory,
        private readonly IndexManagementService $indexManagementService,
        private readonly ConnectionFactory $connectionFactory,
        private readonly IndexerRegistry $indexerRegistry,
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

        $coreMapping = $this->connectionFactory->getCoreMapping();
        $indexers = $this->indexerRegistry->getAll();

        $indexerTypes = [];
        foreach ($indexers as $indexer) {
            $indexerTypes[] = $indexer->getType();
        }

        $indexStats = $this->indexManagementService->getIndexStats();

        $this->assignMultiple($moduleTemplate, [
            'coreMapping' => $coreMapping,
            'coreCount' => $coreMapping === [] ? 1 : count($coreMapping),
            'indexerTypes' => $indexerTypes,
            'indexerCount' => count($indexerTypes),
            'indexStats' => $indexStats,
        ]);

        return $this->renderModuleResponse($moduleTemplate, 'Index');
    }

    public function reindexAction(): ResponseInterface
    {
        try {
            $coreMapping = $this->connectionFactory->getCoreMapping();
            $indexers = $this->indexerRegistry->getAll();

            $this->indexManagementService->reindexAll();

            $this->flashSuccess(
                sprintf(
                    'Full re-index completed across %d core(s) with %d indexer(s).',
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
            $coreMapping = $this->connectionFactory->getCoreMapping();

            if ($coreMapping === []) {
                $this->indexManagementService->clearIndex();
            } else {
                $connection = $this->connectionFactory->getConnection();
                $writeService = $connection->getWriteService();
                $writeService->deleteByQuery('*:*');
                $writeService->commit(false, false);
            }

            $this->flashSuccess('Search index has been cleared.');
        } catch (\Throwable $e) {
            $this->flashError(
                sprintf('Failed to clear index: %s', $e->getMessage()),
            );
        }

        return $this->redirect('index');
    }
}
