<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Tests\Kernel;

use CoolMS\Core\Application\Translation\LabelResolver;
use CoolMS\Core\Bundle\Secret\EnvMasterKeyRing;
use CoolMS\Core\Bundle\Secret\EnvSecretStore;
use CoolMS\Core\Bundle\Ui\UiEntryCatalog;
use CoolMS\Core\Secret\MasterKeyRingInterface;
use CoolMS\Core\Secret\SecretStoreInterface;
use CoolMS\Core\Translation\LabelResolverInterface;
use CoolMS\Core\Ui\UiEntryCatalogInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function count;
use function dirname;
use function restore_exception_handler;
use function set_exception_handler;

/**
 * The bundle alone -- installed with nothing of the application's own -- compiles, and every alias and interface
 * binding it declares resolves to a service it registers (see BundleAloneKernel: each one is made public, so a
 * dangling one fails the compile).
 */
final class BundleAloneResolvesItsAliasesTest extends TestCase
{
    private BundleAloneKernel $kernel;

    /** PHP's exception handler before the boot: the framework installs one, and the test leaves none behind. */
    private mixed $handlerBefore = null;

    #[Test]
    public function everyAliasItDeclaresResolves(): void
    {
        $container = $this->kernel->getContainer();
        $aliases = $container->getParameter(BundleAloneKernel::ALIASES);
        self::assertIsArray($aliases);
        self::assertNotEmpty($aliases, 'the kernel recorded the aliases it made public');

        $unresolved = array_values(array_filter($aliases, static fn (string $id): bool => !$container->has($id)));

        self::assertSame([], $unresolved, count($aliases) . ' aliases declared');
    }

    #[Test]
    public function thePortsThatPointedAtUnregisteredClassesResolveToThem(): void
    {
        $container = $this->kernel->getContainer();

        foreach ([
            SecretStoreInterface::class => EnvSecretStore::class,
            MasterKeyRingInterface::class => EnvMasterKeyRing::class,
            UiEntryCatalogInterface::class => UiEntryCatalog::class,
            LabelResolverInterface::class => LabelResolver::class,
        ] as $port => $class) {
            self::assertInstanceOf($class, $container->get($port), $port);
        }
    }

    protected function setUp(): void
    {
        new Filesystem()->remove(dirname(new BundleAloneKernel('test', false)->getCacheDir()));
        $this->handlerBefore = set_exception_handler(null);
        restore_exception_handler();
        $this->kernel = new BundleAloneKernel('test', false);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        for ($guard = 0; $guard < 10; ++$guard) {
            $current = set_exception_handler(null);
            restore_exception_handler();
            if ($current === $this->handlerBefore) {
                break;
            }
            restore_exception_handler();
        }
    }
}
