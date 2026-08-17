<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection\Compiler;

use CoolMS\CoreBundle\Console\InstallCommand;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Serializer\Encoder\YamlEncoder;

/**
 * Registers vendor services that cannot be handled in the Extension because
 * they must run AFTER all bundle extensions have loaded.
 *
 * YamlEncoder is a Symfony vendor class not scanned by the App\ resource loader.
 * FrameworkBundle may also configure it with positional arguments after all
 * extensions have run. Registering it in the Extension would create a blank
 * definition that FrameworkBundle then tries to patch -- causing an argument-ordering
 * validation error. A compiler pass runs after all extensions, so by
 * the time this executes either:
 *   a) FrameworkBundle already registered it (we just ensure the tag is present), or
 *   b) Nobody registered it yet (we create it with autowiring).
 */
final class CoreServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // InstallCommand -- inject all tagged VFS installers + general module installers.
        // Args are non-autowireable iterables; must be wired here, after the App\ auto-scan.
        if ($container->has(InstallCommand::class)) {
            $container->findDefinition(InstallCommand::class)
                ->setArgument('$installers', new TaggedIteratorArgument('coolms.vfs.installer'))
                ->setArgument('$moduleInstallers', new TaggedIteratorArgument('coolms.module.installer'));
        }
        if ($container->hasDefinition(YamlEncoder::class)) {
            // Already registered by FrameworkBundle -- ensure class and tag are present.
            // FrameworkBundle may register it with class=null (ID-as-class shorthand);
            // Symfony 8's CheckDefinitionValidityPass rejects null-class definitions.
            $def = $container->getDefinition(YamlEncoder::class);
            if (!$def->getClass()) {
                $def->setClass(YamlEncoder::class);
            }
            if (!$def->hasTag('serializer.encoder')) {
                $def->addTag('serializer.encoder');
            }
        } else {
            $container->register(YamlEncoder::class, YamlEncoder::class)
                ->setAutowired(true)
                ->setAutoconfigured(false)
                ->setPublic(false)
                ->addTag('serializer.encoder');
        }
    }
}
