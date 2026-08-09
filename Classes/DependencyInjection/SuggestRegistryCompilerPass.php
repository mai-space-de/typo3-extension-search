<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\DependencyInjection;

use Maispace\MaiSearch\Service\SuggestRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers all services tagged with 'maispace.search.suggest' into the SuggestRegistry.
 */
final class SuggestRegistryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(SuggestRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(SuggestRegistry::class);
        $taggedServices = $container->findTaggedServiceIds('maispace.search.suggest');

        foreach ($taggedServices as $id => $tags) {
            $registryDefinition->addMethodCall('addSuggest', [new Reference($id)]);
        }
    }
}
