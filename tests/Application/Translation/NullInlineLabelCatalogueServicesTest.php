<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Tests\Application\Translation;

use CoolMS\Core\Identity\UserInterface;
use CoolMS\Core\Translation\TranslationCatalogueUnavailableException;
use CoolMS\CoreModule\Translation\NullInlineLabelCatalogueReader;
use CoolMS\CoreModule\Translation\NullInlineLabelCatalogueWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Pins the I18n-optional contract: with no catalogue (I18n module absent),
 * READING per-locale overrides is a safe no-op (empty map → editor shows
 * source labels), while WRITING is a loud failure (a silent drop would lose
 * authored translations). These are the fallbacks bound by
 * {@see \CoolMS\CoreBundle\DependencyInjection\Compiler\TranslationCatalogueFallbackPass}.
 */
final class NullInlineLabelCatalogueServicesTest extends TestCase
{
    #[Test]
    public function readerReturnsNoOverrides(): void
    {
        $reader = new NullInlineLabelCatalogueReader();

        self::assertSame(
            [],
            $reader->readChildLabels(new stdClass(), 'option', ['open', 'closed'], 'label'),
        );
    }

    #[Test]
    public function readerReturnsNoLabelOverrides(): void
    {
        $reader = new NullInlineLabelCatalogueReader();

        self::assertSame([], $reader->readLabels(new stdClass(), ['label']));
    }

    #[Test]
    public function writeChildrenRaisesUnavailable(): void
    {
        $writer = new NullInlineLabelCatalogueWriter();

        $this->expectException(TranslationCatalogueUnavailableException::class);
        $writer->writeChildren(new stdClass(), 'option', ['open' => ['label' => ['uk' => 'X']]], $this->actor());
    }

    #[Test]
    public function writeRaisesUnavailable(): void
    {
        $writer = new NullInlineLabelCatalogueWriter();

        $this->expectException(TranslationCatalogueUnavailableException::class);
        $writer->write(new stdClass(), ['label' => ['uk' => 'X']], $this->actor());
    }

    private function actor(): UserInterface
    {
        // The writer throws before touching the actor; any instance satisfies the type.
        return $this->createStub(UserInterface::class);
    }
}
