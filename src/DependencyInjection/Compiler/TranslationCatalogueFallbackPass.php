<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection\Compiler;

use CoolMS\Core\Translation\InlineLabelCatalogueReaderInterface;
use CoolMS\Core\Translation\InlineLabelCatalogueWriterInterface;
use CoolMS\CoreModule\Translation\NullInlineLabelCatalogueReader;
use CoolMS\CoreModule\Translation\NullInlineLabelCatalogueWriter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Makes the I18n module genuinely optional.
 *
 * The translation CONTRACTS live in Core, but their real implementations
 * (catalogue read/write over VFS XLIFF) live in the I18n module, which aliases
 * the interfaces in its Extension. Consumers (e.g. the Field module's
 * option-label writer + provider) autowire the Core interfaces — so a build
 * WITHOUT the I18n bundle would fail to compile, since nothing binds them.
 *
 * This pass binds Core null-object fallbacks for those interfaces, but ONLY
 * when nobody else already has. Because Core's bundle loads BEFORE I18n's, an
 * Extension-time `if (!hasAlias)` check in Core would always fire first and
 * shadow the real impl. A compiler pass instead runs AFTER every bundle's
 * `load()`, so by the time it executes the I18n alias (when present) already
 * exists and we step aside; when I18n is absent, we supply the fallback.
 *
 * Result: I18n present → real catalogue services win (this pass no-ops).
 *         I18n absent → reads return empty, writes raise a clear error, the
 *         container still compiles and single-locale deployments run.
 *
 * Mirrors {@see CoreServicesPass}'s "register only if nobody else did" shape.
 */
final class TranslationCatalogueFallbackPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->bindFallback(
            $container,
            InlineLabelCatalogueWriterInterface::class,
            NullInlineLabelCatalogueWriter::class,
        );
        $this->bindFallback(
            $container,
            InlineLabelCatalogueReaderInterface::class,
            NullInlineLabelCatalogueReader::class,
        );
    }

    private function bindFallback(ContainerBuilder $container, string $interface, string $impl): void
    {
        // A real impl (I18n's, or any module's) already owns the interface.
        if ($container->hasAlias($interface) || $container->hasDefinition($interface)) {
            return;
        }

        // The null impl is normally auto-registered by the App\ prototype scan;
        // register defensively in case that ever changes, then alias.
        if (!$container->hasDefinition($impl)) {
            $container->register($impl, $impl)
                ->setAutowired(true)
                ->setAutoconfigured(false)
                ->setPublic(false);
        }

        $container->setAlias($interface, $impl)->setPublic(false);
    }
}
