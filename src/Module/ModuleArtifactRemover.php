<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\Module;

use CoolMS\Core\Install\ModuleNavigationRemoverInterface;
use CoolMS\Core\Install\ModuleUninstallerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_merge;
use function in_array;
use function sprintf;

/**
 * The ONE teardown for what a module's installation put in place.
 *
 * Shared by every path that removes, so none of them can drift -- the same
 * reason the theme teardown is one class rather than three copies. Before that
 * unification, theme delete and uninstall bare-deleted the row and leaked both
 * artefacts forever.
 *
 * REMOVE IS NOT PURGE. This undoes what installation put in place and deletes
 * NO data. Concretely, in this ship:
 *
 *   navigation entries   REMOVED -- navi records the owner on the row as
 *                        `meta['contributor']`, so what to take back is known
 *   routes, ORM mappings INERT   -- they leave with the bundle, nothing to do
 *   settings rows        INERT   -- stored under a DECLARED key whose
 *                        declaration leaves with the module; the row survives
 *                        unreadable and is correct again on reinstall
 *   VFS content, seeded  UNTOUCHED -- no recorded owner, and removing them
 *   rows, tables         would be deleting data. That is purge's problem.
 *
 * STRICT VERSUS BEST-EFFORT, stated rather than emergent. {@see removeStrict}
 * reports a fault by throwing: the caller is keeping the module and must know
 * the teardown was incomplete. {@see removeBestEffort} logs and continues: the
 * module is going regardless, and a fault there must not block the rest. Both
 * run the SAME private method, so the difference is only what happens to an
 * exception.
 */
final readonly class ModuleArtifactRemover
{
    /**
     * @param iterable<ModuleUninstallerInterface> $uninstallers
     */
    public function __construct(
        private iterable $uninstallers,
        private ModuleNavigationRemoverInterface $navi,
        private LoggerInterface $logger = new NullLogger(),
        // Deletion runs AS THE SYSTEM USER, never as null: a null actor is
        // accepted by the signature and refused by the permission check, which
        // is the trap ThemeArtifactRemover documents. Navigation removal is a
        // repository operation and needs no actor, so nothing is injected here
        // YET -- when VFS structure joins this teardown it takes the registry
        // the same way, nullable so the module still boots without Identity.
    ) {
    }

    /**
     * @param list<string> $names every name the module is known by
     *
     * @return list<string> what was undone, or would be under $dryRun
     *
     * @throws Throwable if any part of the teardown fails
     */
    public function removeStrict(array $names, bool $dryRun = false): array
    {
        return $this->remove($names, $dryRun, strict: true);
    }

    /**
     * @param list<string> $names every name the module is known by
     *
     * @return list<string> what was undone, or would be under $dryRun
     */
    public function removeBestEffort(array $names, bool $dryRun = false): array
    {
        return $this->remove($names, $dryRun, strict: false);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function remove(array $names, bool $dryRun, bool $strict): array
    {
        $done = [];

        // The module's own uninstallers first: they know what they created,
        // and they may depend on navigation still being present to find it.
        foreach ($this->uninstallers as $uninstaller) {
            if (!in_array($uninstaller->moduleName(), $names, true)) {
                continue;
            }
            try {
                $done = array_merge($done, $uninstaller->uninstall($dryRun));
            } catch (Throwable $e) {
                if ($strict) {
                    throw $e;
                }
                $this->logger->warning('A module uninstaller failed', [
                    'module' => $uninstaller->moduleName(),
                    'uninstaller' => $uninstaller::class,
                    'exception' => $e,
                ]);
                $done[] = sprintf('%s FAILED: %s', $uninstaller::class,
                    $e->getMessage());
            }
        }

        // Navigation last, and always: a module with no uninstaller of its own
        // still has its menu entries taken back, because navi records the owner
        // and therefore does not need the module's help to find them.
        try {
            foreach ($names as $name) {
                $count = $this->navi->unseedModule($name, $dryRun);
                if ($count > 0) {
                    $done[] = sprintf(
                        $dryRun ? 'would remove %d navigation node(s) owned by "%s"'
                                : 'removed %d navigation node(s) owned by "%s"',
                        $count,
                        $name,
                    );
                }
            }
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }
            $this->logger->warning('Navigation unseed failed', [
                'module' => $names,
                'exception' => $e,
            ]);
            $done[] = 'navigation unseed FAILED: ' . $e->getMessage();
        }

        return $done;
    }
}
