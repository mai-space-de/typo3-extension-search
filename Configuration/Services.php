<?php

declare(strict_types=1);

use Maispace\MaiSearch\DependencyInjection\IndexerRegistryCompilerPass;
use Maispace\MaiSearch\DependencyInjection\ResultFormatterRegistryCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

return static function (ContainerBuilder $containerBuilder): void {
    $containerBuilder->addCompilerPass(new IndexerRegistryCompilerPass());
    $containerBuilder->addCompilerPass(new ResultFormatterRegistryCompilerPass());
};
