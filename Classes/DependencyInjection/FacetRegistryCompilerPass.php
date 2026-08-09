<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\DependencyInjection;

use Maispace\MaiSearch\Facet\FacetRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers all services tagged with 'maispace.search.facet_field' into the FacetRegistry.
 */
final class FacetRegistryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(FacetRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(FacetRegistry::class);
        $taggedServices = $container->findTaggedServiceIds('maispace.search.facet_field');

        foreach ($taggedServices as $id => $tags) {
            $registryDefinition->addMethodCall('addFacet', [new Reference($id)]);
        }
    }
}
