# Changelog

All notable changes to `coolms/core-bundle` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

!! Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## 2.0.0-alpha4 - 2026-09-07

### Fixed

**`coolms:install` no longer writes a master key the web server cannot read.**
The generated key file is `0600`, which makes ownership the only thing between
the key and the process that reads it back -- and the writer is frequently not
that process. Under `docker compose exec` the console runs as root while
php-fpm workers drop to `www-data`, so the file landed `0600 root:root` and
`Dotenv` threw on every subsequent request, before the kernel booted. php-fpm
served that fatal as **HTTP 200**, so nothing downstream looked wrong.

The key is now written owned by the user that will read it, still at `0600`.
That user comes from `core.secret_store.key_file_owner`, which defaults to the
`COOLMS_FILE_OWNER` environment variable. When the install runs as root and no
reader is configured, `coolms:install` **refuses before writing anything** and
says what to set -- a refusal that had already created the file would have
caused the exact breakage it refuses to cause. Running as a non-root user is
unaffected: the file is owned by the writer, who is the reader.

Measured on a clean clone of `coolms/coolms` following its documented install,
2026-09-07.

One limit, stated rather than discovered: detecting that the install is running
as root needs `ext-posix`, which is optional. Where it is absent the check does
not run and a configured reader is not applied -- so a host that serves through
php-fpm without that extension keeps the old behaviour.

**`ConfigCacheWarmer` now knows the `settings` config type.** The
runtime module-settings tier writes
`config/modules/generated/settings/<key>--<scope>.yaml`, and `settings` was
absent from `KNOWN_TYPES`. Two consequences, and the second is the one that
matters: every `cache:clear` logged
`unknown config type "settings"` once per saved setting, and no settings file
was validated by anything at all. The warning was the only signal that a whole
config type was unchecked, and it read as noise.

### Changed

**`ConfigCacheWarmer::KNOWN_TYPES` is public.** An application cannot otherwise
assert that the types on its own disk are types this warmer recognises, and a
guard that copies the list is a second list that will drift. The package cannot
know which types an installation uses, so that check belongs to the
installation -- and it needs somewhere to read the authority from.

!! The class docblock no longer repeats the list. It named five types while the
constant held eight, so it documented a validator that had not existed for some
time.

## 2.0.0-alpha3 - 2026-09-04

### Fixed

**Declares `symfony/process`.** `RemoveModuleCommand` builds the container in
a subprocess to check that removal did not break it -- `new Process([PHP_BINARY,
$console, 'about'], ...)` -- and the package never declared the component.
`coolms:module:remove` therefore fatals on a class-not-found in any
application that does not happen to have `symfony/process` for its own
reasons.

!! Invisible here because the CoolMS application requires it directly, and
invisible to CI because the package installs perfectly without it -- nothing
is missing until the command runs. Found by resolving the package alone from
its tag and checking every `use` in its own `src/` against the result.

The third instance of one defect: `symfony/translation-contracts` without
`symfony/translation` in 2.0.0-alpha2, `symfony/config` undeclared across the
three themes, and now this. A manifest omission is invisible in any
application that supplies the missing piece, and every application these
packages have been installed into supplies everything.
## 2.0.0-alpha2 - 2026-09-03

### Fixed

**`coolms:install` no longer reports success after installing nothing.** It
printed two empty sections and `[OK] Installation complete.` when the
application registered no structure installers and no module installers --
so an installation that did nothing was indistinguishable from one that did
everything, and the symptom was the *absence* of an error.

It now states its denominator (`VFS structure -- 7 installer(s)`,
`Module data -- 22 installer(s)`) and refuses, with a non-zero exit, when
both sets are empty. The message names the two causes worth checking: no
CoolMS module registered in `config/bundles.php`, or bundles registered
whose services are not -- the `coolms/*` packages do not register their own
and the consuming application must.

**`coolms:install` under `APP_ENV=test` wrote to a file that environment
never reads.** Symfony skips `.env.local` under `test` on purpose, so a test
run does not depend on one developer's machine -- which makes it the one
file a test-environment install must never write to. The installer appended
a master key to it anyway, and on a machine where two keys already disagreed
that made a third. `MasterKeyProvisioner::envFilePath()` now resolves the
env file the current environment actually reads, and every message names
that file rather than assuming `.env.local`.

**A failing module uninstaller logged an undefined variable.**
`ModuleArtifactRemover` logged `'module' => $module` inside a loop over
`$this->uninstallers`, where no `$module` exists; it now logs
`$uninstaller->moduleName()`. Only reachable when an uninstaller throws,
which is why nothing had caught it.

### Changed

- `RemoveModuleCommand` no longer takes a `string $env` constructor
  argument. Consumers binding it can stop.
- Static-analysis corrections with no behaviour change: duplicated docblocks
  merged in `ModuleArtifactRemover`, `list<>` normalisation in
  `DisabledBundles`, `InstallManifest` and `ModuleCatalog`, a
  `BundleInterface` guard in `ModuleConfigFiles`, and a `glob()` false-result
  guard in a test.
## 2.0.0-alpha1 - 2026-09-01

**A pre-release. It carries no compatibility promise**, which is the honest
statement of where the platform is: the shape is still moving, and a stable tag
would be a promise that cannot be kept yet.

Composer will not install it under default stability. Set

```json
"minimum-stability": "alpha",
"prefer-stable": true
```

in your root `composer.json`, then:

```
composer require coolms/core-bundle:^2.0 coolms/core-doctrine:^2.0
```

`prefer-stable` keeps every other dependency of yours on its newest stable
release, so this loosening applies to what actually needs it and nothing else.

!! **The adapter is part of the command, not an extra.** `coolms/core-bundle`
reaches `coolms/core-module`, which requires a persistence implementation -- a
virtual package: nothing provides it until you choose an implementation, and
Composer reports the virtual name, which reads like a broken package rather
than a missing argument.

!! **A per-package flag is not enough here.** `composer require
coolms/core-bundle:^2.0@alpha` admits the alpha of the package it names and
**nothing behind it**, so the siblings this one pulls in still fail to resolve.
Composer reports it against the sibling, not against what you asked for.

A bare `composer require coolms/core-bundle` is worse than wrong, it is
quietly wrong: it resolves **successfully** to v1.0.1, which is not merely the
previous generation but two releases below that generation's own newest stable
(v1.0.3). Composer backtracks past every release that declares `coolms/core-
module` -- nothing provides its persistence implementation -- until something
resolves, and then reports success.

Releases are suspended while development is moving fast and there are no
external consumers of these packages. This tag establishes the baseline the
documentation describes; nothing follows it until somebody outside the project
installs one, at which point the release policy resumes.

### Removed: `getOptionalBundles()` and the `VENDOR` constant

Both were surface that looked like mechanism and did nothing.

`getOptionalBundles()` was documented as declaring soft dependencies -- bundles
whose absence a module degrades gracefully without. Measured before removing it:
**five bundles overrode it and nothing ever called it.** `boot()` consults
`getRequiredBundles()` and only that, so a soft dependency declared here was read
by no one, could not be wrong in any detectable way, and drifted freely from the
truth. Each of the five names one or two siblings it degrades gracefully
without, and every one of them already states the same thing in its own class
docblock -- which is where a fact nothing executes belongs.

`VENDOR` was a constant holding the string `coolms`, with **zero** references
anywhere -- including inside this package.

**This is a breaking change against the published `1.x`.** A bundle that
overrides `getOptionalBundles()` keeps compiling, because the method it used to
override is simply gone and PHP does not object; nothing calls it either way. A
bundle that reads `static::VENDOR` will fatal, and should inline the literal or
declare its own constant.

The removal lands in a major rather than being deprecated first, because there is
no maintained `1.x` branch on which to publish a deprecation: `develop` carries
the `2.0.x-dev` line and the `1.x` tags are closed. If a deprecation release on
`1.x` is wanted, it needs a maintenance branch first -- a decision, not a
consequence of this change.

### Added: a package can ship module YAML

`ModuleConfigDirsPass` publishes `coolms.module_config_dirs` -- every registered
bundle's `config/` that carries a `modules/` directory -- and the file-driven
loaders read it, so a module ships a definition instead of asking an integrator
to install one into the application's own config.

!! The parent directory is examined only when the bundle path ends in `src`.
Climbing unconditionally leaves the package and lands in the vendor namespace
directory, where a sibling package can share the name being looked for.
### Added: `coolms:install` reports two modules claiming one VFS path

Before it runs any installer, not after: by then the second module has written
into the first one's directory and the evidence is gone. Advisory, because two
modules may legitimately share a root -- it says what it found and installs
anyway.

### Fixed: the installation command in the readme names the adapter

`composer require coolms/core-bundle` on its own did something worse than fail:
**it succeeded.** This bundle pulls in a package that requires a virtual
persistence-implementation package. With no adapter that requirement cannot be
satisfied -- and rather than failing, Composer backtracks to a release of this
bundle from before it declared that dependency at all, then reports success.

Measured: it resolved to v1.0.1 and pulled a template engine from before output
encoding existed. Exit code 0 throughout.

The readme now leads with the command that works:
`composer require coolms/core-bundle coolms/core-doctrine`.

### Fixed: cache pool namespaces are scoped by environment and debug again

`2.1.0` replaced Symfony's default `prefix_seed` and, in doing so, silently
dropped scoping the default had carried. Two environments sharing one Redis
would have shared pool namespaces -- and `cache.rate_limiter` is commonly on
Redis, so that is one environment consuming the other's rate-limit budget.

Symfony's default is `_{kernel.project_dir}.{kernel.container_class}`, and the
container class is `App_Kernel{Env}{Debug}Container`. So it carried four things:

| The default carried | Here |
|---|---|
| environment | **preserved** -- two environments must not share pool namespaces |
| debug flag | **preserved** -- it changes what is compiled into the container |
| project directory | **dropped, deliberately** -- two checkouts of the same artefact at different paths *should* share a namespace |
| kernel class name | **dropped, deliberately** -- it encoded the application plus env plus debug; the first is constant, the other two are now explicit |

### Added: `COOLMS_BUILD_ID` participates in the seed when it is set

The seed covers installed packages and `config/`. It does **not** cover `src/`,
so an application whose code lives there can change without the seed moving.
Hashing the application directory would close that and cost far more than
`config/` does (~23 ms) on every container build, forever.

So: if `COOLMS_BUILD_ID` is set it participates in the seed; if it is absent the
seed is computed exactly as it would be without it. **Default behaviour is
unchanged and nobody pays for a feature they do not use.** Set it to whatever
identifies your build -- an image digest today, `composer.lock`'s hash once the
application is a thin skeleton over vendor. The bundle does not care which, so
the seam survives that transition without changing.

!! It is read at container-compile time, not through `%env()%`. Pool namespaces
are computed from the seed when the container is compiled, so an env placeholder
would be hashed as its own literal text. Changing the value therefore takes
effect on the next container build -- which is when a build identity changes
anyway.

!! Development is deliberately left uncovered. It is covered by what already
covers it: a per-payload schema version, and clearing by hand.

### Changed: sibling constraints move to the v2 generation

- `coolms/core`: `^1.0` to `^2.0`
- `coolms/core-module`: `^1.0` to `^2.0`
- `coolms/core-doctrine` (development): `^1.0` to `^2.0`

!! **This is a minor, not a major, and that is deliberate.** This package
reached major 2 before the platform adopted a shared generation number, and it
did so while still requiring major 1 of its siblings. The v2 generation of those
siblings is **code-identical** to v1 -- their major moved to mark the
generation, not to break anything -- so nothing a caller can reach has changed
here.

**Upgrading:** if your own `composer.json` pins any of those packages at
`^1.0`, widen it to `^2.0`.

The constraints on `coolms/dtmpl` and `coolms/rql` are unchanged. Those are
standalone libraries and do not take the platform generation.

### Upgrading

`getOptionalBundles()` and `VENDOR` are gone from `AbstractCoolmsBundle`. Both
were read by nothing: five bundles overrode the method and no caller existed
anywhere, and the constant had zero references including inside this package.
That is the reason there was nobody to deprecate for, and the reason removing
them is safe. A bundle that overrode the method keeps compiling -- the method it
overrode is simply gone and nothing calls it either way. A bundle that read
`static::VENDOR` must inline the literal or declare its own constant.

The environment and build-identity changes both move the seed, so pools start
empty once. Expect one slow first request per cached thing.

## 2.1.0 - 2026-08-27

Released before releases moved to a weekly train. New behaviour, backward
compatible.

### Cache pools are namespaced by the installed package set

`framework.cache.prefix_seed` is seeded from what actually moves when the code
moves: every installed package's version and resolved reference, read from
Composer's own runtime data, plus a hash of the application's `config/`
directory. It is *prepended*, so an application that names its own
`prefix_seed` keeps it.

Symfony's default seed is derived from the project directory and the container
class, both byte-identical before and after an upgrade -- so by default it
protects nothing. An upgrade could leave yesterday's serialized objects in a
pool for today's classes to read: a fatal on the first property the new class
expects and the stored graph does not carry.

!! This release scoped by neither environment nor debug. See Unreleased.

### One-time cold cache on upgrade

The seed changes once when you take this version, so every pool starts empty.

### Not a substitute for versioning a payload

`prefix_seed` is the floor. A payload whose classes live in the application
rather than in a package still needs a schema version inside its own cache key.

Never seed from a clock or a random source. A value evaluated at container start
gives every worker process a different namespace, and they stop agreeing about
what is cached. Everything here is deterministic by construction, which is the
reason to prefer it.

`CoolMS\CoreBundle\Cache\CacheSeed::compute()` is public if you want to read
the value, and `bin/console debug:container --parameter=cache.prefix.seed`
prints the effective one.

## 2.0.0 - 2026-08-26

### Changed

Require `coolms/dtmpl` `^2.0`. DTMPL 2.0 encodes output by default and renamed
the verbatim block; a major here because moving a consumer across that boundary
is a break for them.

Also removed internal milestone identifiers from source comments.

## 1.0.3 - 2026-08-17

### Fixed

Resolve the persistence seam so a bare checkout installs, and install `ext-zip`
in CI.

## 1.0.2 - 2026-08-17

### Fixed

Declare `coolms/core-module`, which was used but not required, and raise the
`symfony/translation-contracts` floor to 3.4.2.

## 1.0.1 - 2026-08-17

### Fixed

Declare the HTTP client dependencies, which were used but not required.

## 1.0.0 - 2026-08-17

First release. Symfony bundle wiring for `coolms/core`: the module bundle base
class, the DI extension and compiler passes, the install and secret console
commands, the config cache warmer, and the platform's HTTP plumbing.
