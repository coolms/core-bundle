# Changelog

## 2.1.0

New behaviour, backward compatible. Nothing to migrate.

### Cache pools are namespaced by the installed package set

`framework.cache.prefix_seed` is now seeded from what actually moves when the
code moves: every installed package's version and resolved reference (read from
Composer's own runtime data) plus a hash of the application's `config/`
directory. It is *prepended*, so an application that names its own
`prefix_seed` keeps it.

Symfony's default seed is derived from the project directory and the container
class, both of which are byte-identical before and after an upgrade. That is
why an upgrade could leave yesterday's serialized objects in a pool for today's
classes to read — a fatal on the first property the new class expects and the
stored graph does not carry. Seeding from the package set closes that for every
installation, on any `composer update`, without the installation knowing
anything about how it is deployed.

**One-time cold cache on upgrade.** The seed changes once when you take this
version, so every pool starts empty. Expect one slow first request per cached
thing — including a Google Fonts catalogue refresh if you use one.

**What it does not cover: `src/`.** The seed keys on the installed package set
and the application's configuration. Application code is neither. If your
payload classes live in the application rather than in a package, this moves for
a `composer update` and not for the change that actually altered their shape.
Those payloads still need a schema version inside their own cache key —
`prefix_seed` is the floor, not a replacement. The coverage inverts as more of
the platform moves into packages.

Never seed from a clock or a random source. A value evaluated at container start
gives every worker process a different namespace, and they stop agreeing about
what is cached. The package set and the config hash are deterministic by
construction, which is the reason to prefer them.

`CoolMS\CoreBundle\Cache\CacheSeed::compute()` is public if you want to read the
value, and `bin/console debug:container --parameter=cache.prefix.seed` prints
the effective one.
