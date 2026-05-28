<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Widget;

use Maispace\MaiSearch\Widget\DataProvider\SolrHealthDataProvider;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

class SolrHealthWidget implements WidgetInterface, RequestAwareWidgetInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly SolrHealthDataProvider $dataProvider,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly array $options = [],
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $healthData = $this->dataProvider->getHealthData();

        $view = $this->backendViewFactory->create($this->request);
        $view->assignMultiple([
            'health' => $healthData,
            'configuration' => $this->configuration,
            'options' => $this->options,
        ]);

        return $view->render('Widget/SolrHealthWidget');
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
