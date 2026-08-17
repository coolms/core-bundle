<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\DependencyInjection\Compiler;

use CoolMS\Core\Translation\InlineLabelCatalogueReaderInterface;
use CoolMS\Core\Translation\InlineLabelCatalogueWriterInterface;
use CoolMS\CoreBundle\DependencyInjection\Compiler\TranslationCatalogueFallbackPass;
use CoolMS\CoreModule\Translation\NullInlineLabelCatalogueReader;
use CoolMS\CoreModule\Translation\NullInlineLabelCatalogueWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The pass that makes I18n optional. Two branches matter: bind the Core
 * null-objects when the catalogue interfaces are unbound (I18n absent), and
 * stand aside when a real binding already exists (I18n present — its Extension
 * aliased the interfaces before this pass runs).
 */
final class TranslationCatalogueFallbackPassTest extends TestCase
{
    #[Test]
    public function bindsNullFallbacksWhenInterfacesAreUnbound(): void
    {
        $container = new ContainerBuilder();

        new TranslationCatalogueFallbackPass()->process($container);

        self::assertSame(
            NullInlineLabelCatalogueWriter::class,
            (string) $container->getAlias(InlineLabelCatalogueWriterInterface::class),
        );
        self::assertSame(
            NullInlineLabelCatalogueReader::class,
            (string) $container->getAlias(InlineLabelCatalogueReaderInterface::class),
        );
    }

    #[Test]
    public function leavesAnExistingRealAliasUntouched(): void
    {
        $container = new ContainerBuilder();
        // Simulate I18n having aliased the writer to its real impl.
        $container->register('app.real_writer', stdClass::class);
        $container->setAlias(InlineLabelCatalogueWriterInterface::class, 'app.real_writer');

        new TranslationCatalogueFallbackPass()->process($container);

        // Real writer alias preserved (NOT shadowed by the null fallback)...
        self::assertSame(
            'app.real_writer',
            (string) $container->getAlias(InlineLabelCatalogueWriterInterface::class),
        );
        // ...while the still-unbound reader gets the fallback.
        self::assertSame(
            NullInlineLabelCatalogueReader::class,
            (string) $container->getAlias(InlineLabelCatalogueReaderInterface::class),
        );
    }

    #[Test]
    public function respectsAConcreteServiceRegisteredUnderTheInterfaceId(): void
    {
        $container = new ContainerBuilder();
        // A module could register the interface id as a concrete service (no alias).
        $container->register(InlineLabelCatalogueReaderInterface::class, stdClass::class);

        new TranslationCatalogueFallbackPass()->process($container);

        // Pass must defer to it -- no fallback alias minted over a real definition.
        self::assertFalse($container->hasAlias(InlineLabelCatalogueReaderInterface::class));
    }
}
