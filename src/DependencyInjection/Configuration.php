<?php

declare(strict_types=1);

namespace CoolMS\Core\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Core module configuration.
 *
 * Defines the structure for config/packages/coolms_core.yaml.
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('core');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
            ->scalarNode('site_name')
            ->defaultValue('CoolMS')
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('app_version')
            ->defaultValue('2.0.0')
            ->cannotBeEmpty()
            ->end()
            ->scalarNode('default_locale')
            ->defaultValue('en')
            ->info('Platform locale floor. Authoritative knob is coolms_i18n.default_locale, which I18nBundle::prepend() syncs here; set explicitly only to override when i18n is absent.')
            ->end()
            ->arrayNode('supported_locales')
            ->defaultValue([['code' => 'en', 'label' => 'English']])
            ->arrayPrototype()
            ->children()
            ->scalarNode('code')->isRequired()->end()
            ->scalarNode('label')->isRequired()->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('platform_defaults')
            ->info('Platform-wide default user-facing settings (cascade floor). FE/CLDR token shape, not PHP date() tokens. Locale is sourced from default_locale, not here.')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('timezone')->defaultValue('UTC')->cannotBeEmpty()->info('IANA timezone, e.g. Europe/Kyiv.')->end()
            ->scalarNode('date_format')->defaultValue('yyyy-MM-dd')->cannotBeEmpty()->info('CLDR date token used by the FE.')->end()
            ->scalarNode('time_format')->defaultValue('24h')->cannotBeEmpty()->info("'24h' or '12h'.")->end()
            ->scalarNode('week_start')->defaultValue('monday')->cannotBeEmpty()->info("'monday' or 'sunday'.")->end()
            ->scalarNode('accent_color')
            ->defaultNull()
            ->info("Deployment brand accent for the admin UI, as '#rrggbb'. Null keeps the stylesheet's own colour; a user's personal accentColor overrides this.")
            ->validate()
            // Rejected at CONTAINER BUILD rather than at render: this value ends
            // up substituted into a CSS custom property, and a deployment that
            // mistypes it should fail to boot rather than ship a broken palette.
            ->ifTrue(static fn ($v): bool => null !== $v && 1 !== preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v))
            ->thenInvalid('coolms_core.platform_defaults.accent_color must be a six-digit hex colour such as "#F5A623", got %s.')
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('outbound_channels')
            ->info('F3 -- per-channel enablement for outbound distribution channels. A channel NOT listed here is enabled, so installing one needs no config edit; listing it with enabled:false hides it from the admin picker AND makes the write reject it. Keyed by channelId().')
            ->useAttributeAsKey('id')
            ->arrayPrototype()
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultTrue()->info('Set false to withdraw the channel from the picker and reject it on write. Use it for a channel whose delivery config the platform cannot supply yet, so the UI stops offering what the pipeline cannot cash.')->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('secret_store')
            ->info('Platform secret-store backend. The dev default reads $_ENV; there is also a libsodium filesystem store and a HashiCorp Vault driver.')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('driver')->values(['env', 'filesystem', 'vault'])->defaultValue('env')->info("Active backend. 'env' reads environment variables; 'filesystem' is the libsodium encrypted file; 'vault' is HashiCorp Vault KV v2.")->end()
            ->scalarNode('env_prefix')->defaultValue('COOLMS_SECRET_')->cannotBeEmpty()->info("Env-var prefix for the 'env' driver; secret('stripe_api_key') -> COOLMS_SECRET_STRIPE_API_KEY.")->end()
            ->scalarNode('key_file_owner')->defaultValue('%env(default::COOLMS_FILE_OWNER)%')->info('User that must be able to read the generated master key, e.g. www-data. Only consulted when coolms:install runs as root -- root is never the process that serves requests, so a 0600 root-owned env file would be unreadable by the web server. Unset plus a root install is a refusal, not a default.')->end()
            ->arrayNode('filesystem')
            ->info('Settings for the libsodium filesystem driver. The management commands (coolms:secret:*) use these even when env is the active driver.')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('path')->defaultValue('%kernel.project_dir%/var/secrets/secrets.enc')->cannotBeEmpty()->info('Encrypted secrets file, written by coolms:secret:set. Keep it out of VCS.')->end()
            ->scalarNode('key_env')->defaultValue('COOLMS_SECRET_MASTER_KEY')->cannotBeEmpty()->info('Env var holding the base64 32-byte master key (generate via coolms:secret:generate-key).')->end()
            ->end()
            ->end()
            ->arrayNode('vault')
            ->info('Settings for the HashiCorp Vault driver. KV v2 secrets engine.')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('addr')->defaultValue('http://127.0.0.1:8200')->cannotBeEmpty()->info('Vault base URL.')->end()
            ->scalarNode('token_env')->defaultValue('VAULT_TOKEN')->cannotBeEmpty()->info('Env var holding the Vault token.')->end()
            ->scalarNode('path')->defaultValue('secret/data/coolms')->cannotBeEmpty()->info('KV v2 data API path holding the platform secret map (include the data/ segment).')->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
