<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\DependencyInjection;

use Maispace\MaiSearch\Service\ProviderRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers all services tagged 'maispace.search.embedding_provider' /
 * 'maispace.search.chat_provider' into the ProviderRegistry. New providers
 * plug in purely by adding the tag — no core change needed (OCP).
 */
final class ProviderRegistryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ProviderRegistry::class)) {
            return;
        }

        $registryDefinition = $container->getDefinition(ProviderRegistry::class);

        foreach ($container->findTaggedServiceIds('maispace.search.embedding_provider') as $id => $tags) {
            $registryDefinition->addMethodCall('addEmbeddingProvider', [new Reference($id)]);
        }

        foreach ($container->findTaggedServiceIds('maispace.search.chat_provider') as $id => $tags) {
            $registryDefinition->addMethodCall('addChatProvider', [new Reference($id)]);
        }
    }
}
