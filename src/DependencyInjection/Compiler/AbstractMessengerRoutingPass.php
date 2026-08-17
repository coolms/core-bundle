<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Base class for bundle-provided Messenger routing defaults.
 *
 * ## Why NOT a CompilerPassInterface
 *
 * Compiler passes run AFTER all bundle Extensions have loaded and processed
 * their configuration (including FrameworkExtension::registerMessengerConfiguration).
 * By that point, the messenger.senders_locator definition and its inner
 * ServiceLocator are fully built. Adding routing there is fragile: arg-0 must
 * hold string[] (not Reference[]), and the inner ServiceLocator must separately
 * be updated. Symfony provides no public API for this.
 *
 * ## Correct approach -- prependExtensionConfig during build()
 *
 * Bundle::build() is called BEFORE extensions load. Calling
 * $container->prependExtensionConfig('framework', ...) here adds routing to
 * the beginning of FrameworkExtension's config queue; FrameworkExtension then
 * processes it normally during compile() -- including wiring up
 * messenger.senders_locator correctly.
 *
 * App config always wins: configs loaded later (messenger.yaml) are appended
 * AFTER our prepended defaults, so Symfony's merge tree gives them higher
 * priority, exactly as expected.
 *
 * ## Usage
 *
 *   final class MyBundleMessengerRoutingPass extends AbstractMessengerRoutingPass
 *   {
 *       protected function getRouting(): array
 *       {
 *           return [MyMessage::class => 'sync'];
 *       }
 *   }
 *
 * // In MyBundle::build():
 *   public function build(ContainerBuilder $container): void
 *   {
 *       parent::build($container);
 *       (new MyBundleMessengerRoutingPass())->prepend($container);
 *   }
 */
abstract class AbstractMessengerRoutingPass
{
    /**
     * Prepend bundle routing defaults to FrameworkExtension's config queue.
     *
     * Call this from Bundle::build(), NOT from a CompilerPass::process().
     */
    final public function prepend(ContainerBuilder $container): void
    {
        $routing = $this->getRouting();
        if (empty($routing)) {
            return;
        }
        $container->prependExtensionConfig('framework', [
            'messenger' => ['routing' => $routing],
        ]);
    }

    /**
     * Returns the bundle's default routing.
     *
     * Keys -- fully qualified message class names.
     * Values -- transport name as defined in messenger.yaml transports (e.g., 'sync', 'async').
     *
     * @return array<class-string, string>
     */
    abstract protected function getRouting(): array;
}
