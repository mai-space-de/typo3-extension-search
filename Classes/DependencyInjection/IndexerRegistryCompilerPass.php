<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\DependencyInjection;

use Maispace\MaiSearch\Service\IndexerRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers all services tagged with 'maispace.search.indexer' into the IndexerRegistry.
 */
final class IndexerRegistryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(IndexerRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(IndexerRegistry::class);
        $taggedServices = $container->findTaggedServiceIds('maispace.search.indexer');

        foreach ($taggedServices as $id => $tags) {
            $registryDefinition->addMethodCall('addIndexer', [new Reference($id)]);
        }
    }
}
