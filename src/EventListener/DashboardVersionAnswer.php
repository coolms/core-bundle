<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;

/**
 * Answers the version of the person's dashboard arrangements as the response's strong ETag, and keeps the answer out of
 * every shared cache.
 *
 * The widgets read and the layout save and reset leave the version in a request attribute; a successful answer carries
 * it -- the value a save sends back as If-Match. The answer is one person's arrangement, so it is `private, no-store`:
 * a cache between two people must never hand one of them the other's dashboard.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class DashboardVersionAnswer
{
    /** The request attribute a dashboard read, save or reset leaves the version in. */
    public const string VERSION = '_coolms_dashboard_version';

    public function __invoke(ResponseEvent $event): void
    {
        $version = $event->getRequest()->attributes->get(self::VERSION);
        if (!is_string($version) || '' === $version) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-store, private');
        if ($response->isSuccessful()) {
            $response->setEtag($version);
        }
    }
}
