<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\DependencyInjection;

use Maispace\MaiSearch\Service\BackendRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers all services tagged with 'maispace.search.backend' into the BackendRegistry.
 */
final class BackendRegistryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(BackendRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(BackendRegistry::class);
        $taggedServices = $container->findTaggedServiceIds('maispace.search.backend');

        foreach ($taggedServices as $id => $tags) {
            $registryDefinition->addMethodCall('addBackend', [new Reference($id)]);
        }
    }
}
