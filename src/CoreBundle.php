<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle;

use CoolMS\CoreBundle\DependencyInjection\Compiler\CachePrefixSeedPass;
use CoolMS\CoreBundle\DependencyInjection\Compiler\CoreConstantProviderPass;
use CoolMS\CoreBundle\DependencyInjection\Compiler\CoreMessengerRoutingPass;
use CoolMS\CoreBundle\DependencyInjection\Compiler\CoreServicesPass;
use CoolMS\CoreBundle\DependencyInjection\Compiler\ModuleConfigDirsPass;
use CoolMS\CoreBundle\DependencyInjection\Compiler\OptionSourceRegistryPass;
use CoolMS\CoreBundle\DependencyInjection\Compiler\OutboundChannelRegistryConfigPass;
use CoolMS\CoreBundle\DependencyInjection\Compiler\SystemUserPass;
use CoolMS\CoreBundle\DependencyInjection\Compiler\TranslationCatalogueFallbackPass;
use CoolMS\CoreBundle\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class CoreBundle extends AbstractCoolmsBundle
{
    public const string COMPONENT_NAME = 'core';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new CoreServicesPass());
        $container->addCompilerPass(new CoreConstantProviderPass());
        $container->addCompilerPass(new SystemUserPass());
        $container->addCompilerPass(new OptionSourceRegistryPass());
        // F3 per-channel enablement — must run as a PASS because the
        // `App\:` glob would otherwise clobber the argument.
        $container->addCompilerPass(new OutboundChannelRegistryConfigPass());
        // Null-object fallbacks for the translation catalogue seam so the
        // platform compiles + runs when the I18n module is absent. Runs after
        // all extensions, so I18n's real impls (when present) win.
        $container->addCompilerPass(new TranslationCatalogueFallbackPass());
        // prependExtensionConfig must run during build(), before extensions load.
        // Do NOT use addCompilerPass() for routing defaults -- see AbstractMessengerRoutingPass.
        new CoreMessengerRoutingPass()->prepend($container);
        // Namespace every cache pool by the installed package set, so an upgrade
        // cannot leave yesterday's serialized objects readable by today's
        // classes. Same build()-time reason as the routing pass above.
        new CachePrefixSeedPass()->prepend($container);
        // Publishes every registered bundle's config/ that carries module YAML,
        // so a package can ship a definition instead of installing it into the
        // application's own config. build()-time for the same reason as above:
        // the loaders resolve the parameter through #[Autowire].
        new ModuleConfigDirsPass()->prepend($container);
    }

    public function getContainerExtension(): Extension
    {
        return new Extension();
    }
}
