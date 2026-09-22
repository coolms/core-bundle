<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\DependencyInjection\Compiler;

use CoolMS\Core\Application\Config\ConfigWriterInterface;
use CoolMS\Core\Application\Config\NoConfigOverrides;
use CoolMS\Core\Application\Config\ReadOnlyConfigWriter;
use CoolMS\Core\Config\ConfigOverrideReaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Makes the config STORE optional, the way the I18n catalogue is.
 *
 * The platform reads config on every request and has callers that save it (the
 * dashboard arrangement is one), so both ports must resolve or nothing
 * compiles. What holds the stored answers is not the platform's business: the
 * rows are one deployment's operator configuration, and the module that owns
 * them -- Settings, in a full CoolMS install -- aliases both ports in its own
 * Extension.
 *
 * This pass binds the platform's own answers ONLY when nobody else has. It is a
 * PASS rather than an `if (!hasAlias)` in the Extension because Core's bundle
 * loads before the modules': an Extension-time check would always fire first
 * and shadow the real store. A compiler pass runs after every `load()`, so by
 * then the module's aliases (when present) already exist and this steps aside.
 *
 * Result: a store present -> its writer and reader win (this pass no-ops).
 *         no store -> config is read from files and a save refuses out loud.
 *
 * Mirrors {@see TranslationCatalogueFallbackPass}.
 */
final class ConfigStoreFallbackPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->bindFallback($container, ConfigWriterInterface::class, ReadOnlyConfigWriter::class);
        $this->bindFallback($container, ConfigOverrideReaderInterface::class, NoConfigOverrides::class);
    }

    private function bindFallback(ContainerBuilder $container, string $interface, string $impl): void
    {
        // A real store (Settings', or any module's) already owns the port.
        if ($container->hasAlias($interface) || $container->hasDefinition($interface)) {
            return;
        }

        // Normally auto-registered by the vendor prototype scan; registered
        // defensively in case that ever changes, then aliased.
        if (!$container->hasDefinition($impl)) {
            $container->register($impl, $impl)
                ->setAutowired(true)
                ->setAutoconfigured(false)
                ->setPublic(false);
        }

        $container->setAlias($interface, $impl)->setPublic(false);
    }
}
