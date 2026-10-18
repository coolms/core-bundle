<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\ApiPlatform\Provider;

use ApiPlatform\Metadata\Get;
use CoolMS\Core\Application\Config\ConfigLoaderInterface;
use CoolMS\Core\Application\Dashboard\DashboardCatalogue;
use CoolMS\Core\Application\Dashboard\DashboardLayoutProvider;
use CoolMS\Core\Application\Dashboard\DashboardWidgetRegistry;
use CoolMS\Core\Application\Dashboard\PersonalDashboardLayoutsInterface;
use CoolMS\Core\Bundle\ApiPlatform\Provider\DashboardWidgetProvider;
use CoolMS\Core\Bundle\ApiPlatform\Resource\DashboardWidgetInfo;
use CoolMS\Core\Bundle\EventListener\DashboardVersionAnswer;
use CoolMS\Core\Dashboard\DashboardWidget;
use CoolMS\Core\Dashboard\DashboardWidgetProviderInterface;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function array_map;

/**
 * The widgets read: the person's arrangement, its version as the ETag -- read
 * BEFORE the arrangement -- and whether arranging is possible at all.
 */
#[CoversClass(DashboardWidgetProvider::class)]
final class DashboardWidgetProviderTest extends TestCase
{
    /**
     * !! Version first, then data. The other order hands out a version newer
     * than what was drawn when a save lands between the two reads, and the
     * client's next save then overwrites that save unseen.
     */
    #[Test]
    public function theVersionIsReadBeforeTheArrangement(): void
    {
        $personal = new RecordingPersonalLayouts(['main' => [['widget' => 'b'], ['widget' => 'a']]]);
        $request = new Request();

        $resource = $this->provider($personal, $request)->provide(new Get());

        self::assertSame(['version', 'entries main'], $personal->calls);
        self::assertSame('v1', $request->attributes->get(DashboardVersionAnswer::VERSION));
        $drawn = array_map(static fn (DashboardWidgetInfo $w): string => $w->id, $resource->widgets);
        self::assertSame(['b', 'a'], $drawn);
        self::assertTrue($resource->canArrange);
    }

    #[Test]
    public function anInstallationThatKeepsNoArrangementsSaysSo(): void
    {
        $provider = $this->provider(new RecordingPersonalLayouts([], arrangeable: false), new Request());

        $resource = $provider->provide(new Get());

        self::assertFalse($resource->canArrange);
    }

    private function provider(RecordingPersonalLayouts $personal, Request $request): DashboardWidgetProvider
    {
        $widgets = [];
        foreach (['a', 'b'] as $id) {
            $widgets[] = new DashboardWidget(
                id: $id,
                label: $id,
                icon: 'bi-graph-up',
                endpoint: '/api/v1/' . $id,
                valuePath: 'n',
            );
        }
        $offered = new class($widgets) implements DashboardWidgetProviderInterface {
            /** @param list<DashboardWidget> $widgets */
            public function __construct(private readonly array $widgets)
            {
            }

            public function dashboardWidgets(): array
            {
                return $this->widgets;
            }
        };
        $registry = new DashboardWidgetRegistry([$offered], new class implements AuthorizationCheckerInterface {
            public function isGranted(
                mixed $attribute,
                mixed $subject = null,
                ?AccessDecision $accessDecision = null,
            ): bool {
                return true;
            }
        });
        $noDefault = new class implements ConfigLoaderInterface {
            public function load(string $type, string $id, ?string $themeSlug = null): ?array
            {
                return null;
            }
        };
        $stack = new RequestStack();
        $stack->push($request);

        $layouts = new DashboardLayoutProvider($noDefault, new NullLogger(), $personal);

        return new DashboardWidgetProvider(new DashboardCatalogue($registry, $layouts, $personal), $stack);
    }
}

/** A person's arrangements that remembers what it was asked, in order. */
final class RecordingPersonalLayouts implements PersonalDashboardLayoutsInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @param array<string, list<mixed>> $layouts */
    public function __construct(
        private readonly array $layouts,
        private readonly bool $arrangeable = true,
    ) {
    }

    public function canArrange(): bool
    {
        return $this->arrangeable;
    }

    public function version(): string
    {
        $this->calls[] = 'version';

        return 'v1';
    }

    public function entries(string $dashboard): ?array
    {
        $this->calls[] = 'entries ' . $dashboard;

        return $this->layouts[$dashboard] ?? null;
    }

    public function save(string $dashboard, array $entries, ?string $expectedVersion): string
    {
        throw new LogicException('A read never writes.');
    }

    public function clear(string $dashboard, ?string $expectedVersion): string
    {
        throw new LogicException('A read never writes.');
    }
}
