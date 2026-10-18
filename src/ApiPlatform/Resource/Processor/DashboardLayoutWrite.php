<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\ApiPlatform\Resource\Processor;

use Closure;
use CoolMS\Core\Application\Dashboard\DashboardArrangingUnavailableException;
use CoolMS\Core\Application\Dashboard\StaleDashboardLayoutsException;
use CoolMS\Core\Bundle\EventListener\DashboardVersionAnswer;
use CoolMS\Core\Bundle\Http\IfMatchVersion;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

use function is_string;

/**
 * What a layout save and a layout reset share: the dashboard and the version from the request, and the answers.
 *
 *   - the section from `?section=`, absent or empty being the main dashboard;
 *   - the version from If-Match -- none named is a write that names no version, applied and counted by the host;
 *   - 412 when the person's arrangements changed since that version (nothing was written), 501 when the installation
 *     keeps no arrangement per person, 422 for a widget the viewer is not offered or a dashboard id that is not one;
 *   - the version written, left for {@see DashboardVersionAnswer} to answer as the ETag.
 *
 * !! The section is read from the REQUEST, not `$context['filters']`. API-Platform fills `filters` for the read side
 * but not for a Put or a Delete, so the first cut silently arranged the MAIN dashboard whichever tab you were on.
 */
final readonly class DashboardLayoutWrite
{
    public function __construct(
        private RequestStack $requests,
    ) {
    }

    public function section(): ?string
    {
        $section = $this->requests->getCurrentRequest()?->query->get('section');

        return is_string($section) && '' !== $section ? $section : null;
    }

    /**
     * The one version If-Match names, or null for none.
     *
     * A header naming several is refused (412) rather than read as none: the client sent a precondition, and applying
     * the write unversioned would ignore it. An arrangement has one version to name -- the one the client read.
     */
    public function expectedVersion(): ?string
    {
        $header = $this->requests->getCurrentRequest()?->headers->get('If-Match');
        if (IfMatchVersion::namesSeveral($header)) {
            throw new PreconditionFailedHttpException('Name the one version this arrangement was read at.');
        }

        return IfMatchVersion::parse($header);
    }

    /**
     * Runs the write and answers its outcome.
     *
     * @param Closure(): string $write returns the version written
     *
     * @return string the version written
     */
    public function answer(Closure $write): string
    {
        try {
            $version = $write();
        } catch (StaleDashboardLayoutsException $e) {
            throw new PreconditionFailedHttpException($e->getMessage(), $e);
        } catch (DashboardArrangingUnavailableException $e) {
            throw new HttpException(Response::HTTP_NOT_IMPLEMENTED, $e->getMessage(), $e);
        } catch (InvalidArgumentException $e) {
            // An id no installed module offers, or a dashboard that is not one. 422 rather than 400 -- the request is
            // well-formed JSON asking for something the dashboard cannot do.
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        $this->requests->getCurrentRequest()?->attributes->set(DashboardVersionAnswer::VERSION, $version);

        return $version;
    }
}
