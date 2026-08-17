# coolms/core-bundle

[![CI](https://github.com/coolms/core-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/coolms/core-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/coolms/core-bundle)](https://packagist.org/packages/coolms/core-bundle)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)](https://www.php.net/releases/8.5/en.php)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**Symfony integration for the CoolMS platform kernel.** The bundle base class
every module extends, the DI extension and compiler passes, the install and
secret console commands, and the platform's HTTP plumbing.

Notably it contains **no persistence**. Which storage backs the kernel's
contracts is decided by whichever adapter package is installed — see
[`coolms/core-doctrine`](https://github.com/coolms/core-doctrine).

## Installation

```bash
composer require coolms/core-bundle
```

```php
// config/bundles.php
CoolMS\CoreBundle\CoreBundle::class => ['all' => true],
```

## What is in here

| Area | What it does |
|---|---|
| `AbstractCoolmsBundle` | the base class every platform module's bundle extends |
| `DependencyInjection/` | the Core extension, `AbstractExtension` for module extensions, and the platform compiler passes |
| `Console/` | `coolms:install` and the `coolms:secret:*` commands |
| `Secret/` | the env, encrypted-file and Vault secret stores |
| `Controller/`, `EventListener/`, `Http/` | the base controller, RFC 7807 exception rendering, page-cache directives |
| `Config/`, `Json/`, `Option/`, `Webhook/` | the config cache warmer, JSONC decoding, option sources, webhook signature verification |

## Extending the platform

A module's bundle extends `AbstractCoolmsBundle`, and its DI extension extends
`AbstractExtension`:

```php
use CoolMS\CoreBundle\AbstractCoolmsBundle;
use CoolMS\CoreBundle\DependencyInjection\AbstractExtension;

final class MyModuleBundle extends AbstractCoolmsBundle
{
    public function getContainerExtension(): Extension
    {
        return new MyModuleExtension();
    }
}
```

`AbstractExtension` provides `setResolveTargetEntities()`, which accumulates the
interface-to-concrete map the platform resolves at compile time.

> Declare `getAlias()` explicitly in every extension. Symfony derives the alias
> from the class short name, and `Extension` minus the `Extension` suffix is the
> empty string — so two modules that both omit it silently collide on every
> service id built from the alias.

## Related packages

| Package | Role |
|---|---|
| [`coolms/core`](https://github.com/coolms/core) | kernel contracts |
| [`coolms/core-module`](https://github.com/coolms/core-module) | application services |
| [`coolms/core-doctrine`](https://github.com/coolms/core-doctrine) | persistence adapter |

## License

MIT. See [LICENSE](LICENSE).
