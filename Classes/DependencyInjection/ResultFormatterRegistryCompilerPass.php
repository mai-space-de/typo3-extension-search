<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\DependencyInjection;

use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers all services tagged with 'maispace.search.result_formatter' into the ResultFormatterRegistry.
 */
final class ResultFormatterRegistryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ResultFormatterRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(ResultFormatterRegistry::class);
        $taggedServices = $container->findTaggedServiceIds('maispace.search.result_formatter');

        foreach ($taggedServices as $id => $tags) {
            $registryDefinition->addMethodCall('addFormatter', [new Reference($id)]);
        }
    }
}
