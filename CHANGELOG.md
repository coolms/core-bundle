# Changelog

## Unreleased

Rides the next Tuesday release train. Nothing here has shipped yet.

### Fixed: cache pool namespaces are scoped by environment and debug again

`2.1.0` replaced Symfony's default `prefix_seed` and, in doing so, silently
dropped scoping the default had carried. Two environments sharing one Redis
would have shared pool namespaces — and `cache.rate_limiter` is commonly on
Redis, so that is one environment consuming the other's rate-limit budget.

Symfony's default is `_{kernel.project_dir}.{kernel.container_class}`, and the
container class is `App_Kernel{Env}{Debug}Container`. So it carried four things:

| The default carried | Here |
|---|---|
| environment | **preserved** — two environments must not share pool namespaces |
| debug flag | **preserved** — it changes what is compiled into the container |
| project directory | **dropped, deliberately** — two checkouts of the same artefact at different paths *should* share a namespace |
| kernel class name | **dropped, deliberately** — it encoded the application plus env plus debug; the first is constant, the other two are now explicit |

### Added: `COOLMS_BUILD_ID` participates in the seed when it is set

The seed covers installed packages and `config/`. It does **not** cover `src/`,
so an application whose code lives there can change without the seed moving.
Hashing the application directory would close that and cost far more than
`config/` does (~23 ms) on every container build, forever.

So: if `COOLMS_BUILD_ID` is set it participates in the seed; if it is absent the
seed is computed exactly as it would be without it. **Default behaviour is
unchanged and nobody pays for a feature they do not use.** Set it to whatever
identifies your build — an image digest today, `composer.lock`'s hash once the
application is a thin skeleton over vendor. The bundle does not care which, so
the seam survives that transition without changing.

⚠️ It is read at container-compile time, not through `%env()%`. Pool namespaces
are computed from the seed when the container is compiled, so an env placeholder
would be hashed as its own literal text. Changing the value therefore takes
effect on the next container build — which is when a build identity changes
anyway.

⚠️ Development is deliberately left uncovered. It is covered by what already
covers it: a per-payload schema version, and clearing by hand.

### Upgrading

Both changes move the seed, so pools start empty once. Expect one slow first
request per cached thing.

---

## 2.1.0

Released 2026-08-27, before releases moved to a weekly train. New behaviour,
backward compatible.

### Cache pools are namespaced by the installed package set

`framework.cache.prefix_seed` is seeded from what actually moves when the code
moves: every installed package's version and resolved reference, read from
Composer's own runtime data, plus a hash of the application's `config/`
directory. It is *prepended*, so an application that names its own
`prefix_seed` keeps it.

Symfony's default seed is derived from the project directory and the container
class, both byte-identical before and after an upgrade — so by default it
protects nothing. An upgrade could leave yesterday's serialized objects in a
pool for today's classes to read: a fatal on the first property the new class
expects and the stored graph does not carry.

⚠️ This release scoped by neither environment nor debug. See Unreleased.

### One-time cold cache on upgrade

The seed changes once when you take this version, so every pool starts empty.

### Not a substitute for versioning a payload

`prefix_seed` is the floor. A payload whose classes live in the application
rather than in a package still needs a schema version inside its own cache key.

Never seed from a clock or a random source. A value evaluated at container start
gives every worker process a different namespace, and they stop agreeing about
what is cached. Everything here is deterministic by construction, which is the
reason to prefer it.

`CoolMS\CoreBundle\Cache\CacheSeed::compute()` is public if you want to read the
value, and `bin/console debug:container --parameter=cache.prefix.seed` prints
the effective one.
