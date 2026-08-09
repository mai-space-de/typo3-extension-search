<?php

declare(strict_types=1);

use Maispace\MaiSearch\DependencyInjection\BackendRegistryCompilerPass;
use Maispace\MaiSearch\DependencyInjection\FacetRegistryCompilerPass;
use Maispace\MaiSearch\DependencyInjection\IndexerRegistryCompilerPass;
use Maispace\MaiSearch\DependencyInjection\ProviderRegistryCompilerPass;
use Maispace\MaiSearch\DependencyInjection\ResultFormatterRegistryCompilerPass;
use Maispace\MaiSearch\DependencyInjection\SuggestRegistryCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $containerBuilder): void {
    $containerBuilder->addCompilerPass(new IndexerRegistryCompilerPass());
    $containerBuilder->addCompilerPass(new ResultFormatterRegistryCompilerPass());
    $containerBuilder->addCompilerPass(new BackendRegistryCompilerPass());
    $containerBuilder->addCompilerPass(new ProviderRegistryCompilerPass());
    $containerBuilder->addCompilerPass(new FacetRegistryCompilerPass());
    $containerBuilder->addCompilerPass(new SuggestRegistryCompilerPass());
};
