<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Module;

use CoolMS\CoreBundle\AbstractCoolmsBundle;

use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function is_array;
use function is_file;
use function is_string;
use function ksort;
use function ltrim;
use function str_replace;
use function strtolower;
use function usort;

/**
 * Every bundle the application declares, and what each one needs.
 *
 * Reads `config/bundles.php` DIRECTLY rather than asking the kernel, because it
 * must see bundles that are currently disabled -- the kernel, by construction,
 * cannot.
 *
 * Everything it reads is STATIC: `COMPONENT_NAME` is a class constant and
 * `getRequiredBundles()` is a static method, so the whole dependency graph is
 * available without booting anything. That is what makes refusing a disable
 * cheaper than discovering it as a LogicException at boot.
 */
final readonly class ModuleCatalog
{
    public function __construct(
        private string $projectDir,
        private DisabledBundles $disabled,
    ) {
    }

    /**
     * Bundle class => module name, for every bundle that declares one.
     *
     * @return array<class-string, string>
     */
    public function modules(): array
    {
        $out = [];
        foreach ($this->bundleClasses() as $class) {
            $name = $this->moduleNameOf($class);
            if ('' !== $name) {
                $out[$class] = $name;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Resolve what an operator typed to exactly one bundle class.
     *
     * ⚠️ The declared names are NOT uniform -- `sso`, `media` and `spreadsheet`
     * sit beside `coolms_centrifugo` and `coolms_terminal` -- so matching is
     * lenient: case, hyphens/underscores and a `coolms_` prefix are all
     * ignored. Aligning the names themselves is a breaking change to config
     * keys and DI aliases, so it is recorded separately rather than done here.
     *
     * @return class-string|null
     */
    public function resolve(string $typed): ?string
    {
        $want = $this->normalise($typed);
        foreach ($this->modules() as $class => $name) {
            if ($this->normalise($name) === $want) {
                return $class;
            }
        }

        // Also accept the bundle's short class name, which is what appears in
        // a LogicException and therefore what an operator is likely to paste.
        foreach ($this->modules() as $class => $_) {
            $short = $this->shortName($class);
            if ($this->normalise($short) === $want
                || $this->normalise(str_replace('Bundle', '', $short)) === $want) {
                return $class;
            }
        }

        return null;
    }



    /**
     * Which module a class belongs to, by namespace prefix.
     *
     * An installer does not declare its module -- nothing records that -- so
     * this matches the class against the namespace of each known bundle --
     * an installer declared inside a bundle's namespace belongs to it.
     * The longest matching prefix wins, so a nested module cannot be swallowed
     * by a shorter neighbour.
     *
     * Returns '' when nothing matches, and the caller records it as unattributed
     * rather than guessing. A wrong attribution is worse than a missing one:
     * it would put another module's artefacts under this one's name.
     */
    public function moduleOf(string $class): string
    {
        $best = '';
        $bestLen = 0;
        foreach ($this->modules() as $bundle => $name) {
            $ns = substr($bundle, 0, (int) strrpos($bundle, '\\') + 1);
            if ('' !== $ns && str_starts_with($class, $ns)
                && strlen($ns) > $bestLen) {
                $best = $name;
                $bestLen = strlen($ns);
            }
        }

        return $best;
    }

    /**
     * Every name this module is known by, most specific first.
     *
     * ⚠️ A module does NOT have one name. The bundle declares `COMPONENT_NAME`,
     * but a navigation contributor records whatever its own `getModuleName()`
     * returns, and MEASURED across the application those disagree for 9 of 34:
     * the bundle says `coolms_vfs` while the rows say `vfs`. Removing by the
     * bundle's name alone matches nothing and reports success.
     *
     * Aligning the names is a breaking change to config keys and DI aliases, so
     * this returns both and the caller reports which one matched.
     *
     * @return list<string>
     */
    public function candidateNames(string $class): array
    {
        $declared = $this->moduleNameOf($class);
        if ('' === $declared) {
            return [];
        }

        $names = [$declared];
        $stripped = $this->normalise($declared);
        if ($stripped !== $declared) {
            $names[] = $stripped;
        }

        return $names;
    }

    /**
     * Bundles that hard-require $class and are not themselves disabled.
     *
     * Disabling something another bundle requires makes `boot()` throw, so the
     * application stops starting. Naming the dependents is strictly better than
     * that.
     *
     * @param list<class-string> $alsoDisabled treat these as already gone
     *
     * @return list<class-string>
     */
    public function dependentsOf(string $class, array $alsoDisabled = []): array
    {
        $out = [];
        foreach ($this->bundleClasses() as $candidate) {
            if ($candidate === $class || in_array($candidate, $alsoDisabled, true)) {
                continue;
            }
            foreach ($this->requiredBy($candidate) as $required) {
                if ($required === $class) {
                    $out[] = $candidate;
                    break;
                }
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @return list<class-string>
     */
    public function bundleClasses(): array
    {
        $path = $this->projectDir . '/config' . '/bundles.php';
        if (!is_file($path)) {
            return [];
        }

        /** @var mixed $map */
        $map = require $path;

        $loading = is_array($map) ? array_keys($map) : [];

        // Plus what is currently DISABLED. bundles.php filters those out, so
        // reading it alone shows only what is loading -- and a module that has
        // already been removed would come back as "no module named", which is
        // wrong and would make a second `remove` an error. Running it twice
        // must be as harmless as running install twice.
        $all = array_merge($loading, $this->disabled->all());

        return array_values(array_unique(array_filter(
            $all,
            static fn (mixed $k): bool => is_string($k) && class_exists($k),
        )));
    }

    public function moduleNameOf(string $class): string
    {
        if (!is_subclass_of($class, AbstractCoolmsBundle::class)) {
            return '';
        }

        /** @var string $name */
        $name = $class::COMPONENT_NAME;

        return $name;
    }

    /**
     * @return list<class-string>
     */
    private function requiredBy(string $class): array
    {
        if (!is_subclass_of($class, AbstractCoolmsBundle::class)) {
            return [];
        }

        return array_values($class::getRequiredBundles());
    }

    private function shortName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return false === $pos ? $class : substr($class, $pos + 1);
    }

    private function normalise(string $value): string
    {
        $value = strtolower(str_replace(['-', ' '], '_', ltrim($value)));

        return str_starts_with($value, 'coolms_')
            ? substr($value, 7)
            : $value;
    }
}
