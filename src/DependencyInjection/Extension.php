<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\DependencyInjection;

use CoolMS\Core\Api\ApiResourceInstallerInterface;
use CoolMS\Core\Backup\BackupContributorInterface;
use CoolMS\Core\ChangeFeed\SyncBlobContributorInterface;
use CoolMS\Core\ChangeFeed\SyncSectionPartitionInterface;
use CoolMS\Core\Channel\OutboundChannelInterface;
use CoolMS\Core\Config\PhpFileLoader;
use CoolMS\Core\Config\PlatformDefaults;
use CoolMS\Core\Config\SupportedLocalesProvider;
use CoolMS\Core\Config\XmlFileLoader;
use CoolMS\Core\Config\YamlFileLoader;
use CoolMS\Core\Dashboard\DashboardWidgetProviderInterface;
use CoolMS\Core\Install\ModuleInstallerInterface;
use CoolMS\Core\Option\OptionSourceProviderInterface;
use CoolMS\Core\Outbox\OutboxPublisherInterface;
use CoolMS\Core\Registry\ComponentRegistry;
use CoolMS\Core\Retention\RetentionPrunerInterface;
use CoolMS\Core\Secret\SecretStoreInterface;
use CoolMS\Core\Serializer\AlreadyInstantiatedObjectDenormalizer;
use CoolMS\Core\Serializer\DateTimeObjectDenormalizer;
use CoolMS\Core\Service\TransliterationRuleSetInterface;
use CoolMS\Core\Translation\LabelResolverInterface;
use CoolMS\CoreBundle\Config\ConfigCacheWarmer;
use CoolMS\CoreBundle\Json\JsoncDecoder;
use CoolMS\CoreBundle\Outbox\DispatchingOutboxPublisher;
use CoolMS\CoreBundle\Secret\EnvSecretStore;
use CoolMS\CoreBundle\Secret\FilesystemEncryptedStore;
use CoolMS\CoreBundle\Secret\VaultSecretStore;
use CoolMS\CoreModule\ApiManifest\ApiManifestContributorInterface;
use CoolMS\CoreModule\Config\ChainedConfigLoader;
use CoolMS\CoreModule\Config\ChainedConfigWriter;
use CoolMS\CoreModule\Config\ConfigLoaderInterface;
use CoolMS\CoreModule\Config\ConfigWriterInterface;
use CoolMS\CoreModule\Config\DbConfigWriter;
use CoolMS\CoreModule\Config\FileConfigLoader;
use CoolMS\CoreModule\Config\FileConfigWriter;
use CoolMS\CoreModule\Json\JsoncDecoderInterface;
use CoolMS\CoreModule\Service\LocalizedSlugger;
use CoolMS\CoreModule\Translation\LabelResolver;
use LogicException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class Extension extends AbstractExtension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('coolms.site_name', $config['site_name']);
        $container->setParameter('coolms.app_version', $config['app_version']);

        // F3 per-channel enablement. Read by
        // OutboundChannelRegistryConfigPass rather than an `#[Autowire(param:)]`
        // on the registry, so configurable wiring stays in configuration and
        // the `App\:` services glob cannot drop the argument.
        $container->setParameter('coolms_core.outbound_channels', $config['outbound_channels']);

        $container->register(SupportedLocalesProvider::class)
            ->setArgument('$locales', $config['supported_locales'])
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(false);

        // F6 Phase 1 -- platform-wide default user-facing
        // settings. Locale is sourced from `default_locale` (which
        // I18nBundle::prepend() syncs from coolms_i18n.default_locale),
        // keeping a single locale authority. The other four come from
        // the platform_defaults config node. Like SupportedLocalesProvider
        // this is a plain config-bound VO, excluded from autowiring.
        $container->register(PlatformDefaults::class)
            ->setArgument('$locale', $config['default_locale'])
            ->setArgument('$timezone', $config['platform_defaults']['timezone'])
            ->setArgument('$dateFormat', $config['platform_defaults']['date_format'])
            ->setArgument('$timeFormat', $config['platform_defaults']['time_format'])
            ->setArgument('$weekStart', $config['platform_defaults']['week_start'])
            ->setArgument('$accentColor', $config['platform_defaults']['accent_color'])
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(false);

        // F1 -- platform secret store. The concrete stores (EnvSecretStore,
        // FilesystemEncryptedStore) + the encrypted-file codec are registered
        // by the App\ services glob (autowired); their scalar constructor args
        // resolve from these parameters via #[Autowire('%...%')]. We bind via
        // parameters rather than an explicit register() here because
        // services.yaml's glob loads AFTER this extension and would otherwise
        // re-register the same ids and clobber an explicit definition (the
        // `coolms:secret:*` commands inject the codec, so it must be a normal
        // autowirable service). The driver below only selects which concrete
        // the SecretStoreInterface aliases to; the encrypted-file tooling is
        // available regardless so an operator can prepare the file before
        // switching `driver` to `filesystem`. New backends (Vault) add an enum
        // value (Configuration) + a `match` arm here.
        $container->setParameter('coolms.secret_store.env_prefix', $config['secret_store']['env_prefix']);
        $container->setParameter('coolms.secret_store.fs_path', $config['secret_store']['filesystem']['path']);
        $container->setParameter('coolms.secret_store.fs_key_env', $config['secret_store']['filesystem']['key_env']);
        $container->setParameter('coolms.secret_store.vault_addr', $config['secret_store']['vault']['addr']);
        $container->setParameter('coolms.secret_store.vault_token_env', $config['secret_store']['vault']['token_env']);
        $container->setParameter('coolms.secret_store.vault_path', $config['secret_store']['vault']['path']);

        $secretStoreId = match ($config['secret_store']['driver']) {
            'env' => EnvSecretStore::class,
            'filesystem' => FilesystemEncryptedStore::class,
            'vault' => VaultSecretStore::class,
            default => throw new LogicException('Unsupported coolms_core.secret_store.driver: ' . $config['secret_store']['driver']),
        };
        $container->setAlias(SecretStoreInterface::class, $secretStoreId);

        // Register interface-to-class resolution for runtime lookup
        $this->setResolveTargetEntities($container, []);
        $this->registerServices($container);

        // ApiManifest contributor tag -- implementations are auto-collected by ApiManifestBuilder
        // via #[AutowireIterator('coolms.api_manifest_contributor')].
        $container->registerForAutoconfiguration(ApiManifestContributorInterface::class)
            ->addTag('coolms.api_manifest_contributor');

        // API resource installers -- collected by ApiResourceSyncService to populate VFS resource nodes.
        $container->registerForAutoconfiguration(ApiResourceInstallerInterface::class)
            ->addTag('coolms.api.resource.installer');

        // Module data installers -- collected by ServiceWiringPass and called by coolms:install.
        // Priorities are applied after auto-scan by ModuleInstallerPriorityPass.
        $container->registerForAutoconfiguration(ModuleInstallerInterface::class)
            ->addTag('coolms.module.installer');

        // OptionSource — tagged providers that advertise platform-wide
        // select datasources (timezones, handlers, users, …). The
        // registry's `$providers` iterable arg is bound post-compile
        // by OptionSourceRegistryPass; here we just register the
        // autoconfigure tag so any implementing class lands in the
        // tagged-iterator without manual DI.
        $container->registerForAutoconfiguration(OptionSourceProviderInterface::class)
            ->addTag('coolms.option.source');

        // Retention pruners — any module's retention sweep (analytics events,
        // spam comments, expired credentials, ...) implements the L0
        // RetentionPrunerInterface and is auto-collected by RetentionPruneRunner
        // (via #[AutowireIterator('coolms.retention.pruner')]) so the platform
        // runs ALL retention in one place: `coolms:retention:prune` + the
        // `retention.prune` scheduled handler.
        $container->registerForAutoconfiguration(RetentionPrunerInterface::class)
            ->addTag('coolms.retention.pruner');

        // Dashboard widgets — any module with something worth showing on
        // /admin implements DashboardWidgetProviderInterface and is collected
        // by DashboardWidgetRegistry. Core hosts the seam and knows
        // nothing about what any widget MEANS: each one carries its own
        // endpoint, so its data, its permissions and its refresh stay with the
        // module that owns them and the dashboard owns only placement.
        $container->registerForAutoconfiguration(DashboardWidgetProviderInterface::class)
            ->addTag('coolms.core.dashboard_widget_provider');

        // Backup contributors — any module's backup/restore of its own data slice
        // (Calendar tables, later VFS content+blobs, Identity, Workflow, ...)
        // implements the L0 BackupContributorInterface and is auto-collected by
        // BackupRunner (via #[AutowireIterator('coolms.backup.contributor')]) so
        // the platform backs up/restores everything in one place:
        // `coolms:backup:create` + `coolms:backup:restore`.
        $container->registerForAutoconfiguration(BackupContributorInterface::class)
            ->addTag('coolms.backup.contributor');

        // Sync blob contributors — any module whose SYNCED ROWS refer to bytes
        // those rows don't contain (VFS nodes → their content-addressed blobs) implements
        // the L0 SyncBlobContributorInterface and is auto-collected by SyncBlobRegistry
        // (via #[AutowireIterator('coolms.sync.blob.contributor')]). Rows sync by delta,
        // bytes sync by reference — a separate channel on purpose, so a lean feed stays
        // lean. The sync module drives it over HTTP without importing the owning module.
        $container->registerForAutoconfiguration(SyncBlobContributorInterface::class)
            ->addTag('coolms.sync.blob.contributor');

        // Sync section partitions — a module whose synced tables
        // partition by site section (Section: the `/content/<slug>` namespace over
        // VFS's tables) implements the L0 SyncSectionPartitionInterface; the sync
        // surface's EdgeScopePolicy collects them by tag to enforce a per-edge
        // `sections` scope without importing the owning module.
        $container->registerForAutoconfiguration(SyncSectionPartitionInterface::class)
            ->addTag('coolms.sync.section_partition');

        // Transliteration rule sets — national Cyrillic/umlaut→ASCII maps
        // (ru, de, …) that LocalizedSlugger applies before the generic ICU
        // fold. Shipped by I18n (or any module), collected via
        // #[AutowireIterator(TransliterationRuleSetInterface::TAG)]. The
        // slug ENGINE is Core, the per-locale DATA is i18n — Articles/Page
        // naming (freeze-on-publish) is the first consumer.
        $container->registerForAutoconfiguration(TransliterationRuleSetInterface::class)
            ->addTag(TransliterationRuleSetInterface::TAG);

        // Outbound channels (F3) — any module's delivery adapter (Content ships
        // `rss`, a future Connector ships `telegram`, …) implements the L0
        // OutboundChannelInterface and is auto-collected by OutboundChannelRegistry
        // (via #[AutowireIterator('coolms.outbound_channel')]) so the Workflow
        // `channel:publish` service task can fan a message out to any channel.
        $container->registerForAutoconfiguration(OutboundChannelInterface::class)
            ->addTag('coolms.outbound_channel');

        // Config infrastructure -- FileConfigLoader and ConfigCacheWarmer
        $container->register(FileConfigLoader::class)
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setPublic(false);

        // The config STORE. Reads layer the DB over the files and
        // writes go wherever this host allows, so a feature that reads its
        // config can save it without knowing which of the two it got.
        //
        // ⚠️ ConfigLoaderInterface now points at the CHAINED loader. Every
        // existing consumer keeps working — the chain falls through to
        // FileConfigLoader whenever no override row exists, which is always
        // until something writes one.
        foreach ([ChainedConfigLoader::class, FileConfigWriter::class, DbConfigWriter::class] as $class) {
            $container->register($class)
                ->setAutowired(true)
                ->setAutoconfigured(false)
                ->setPublic(false);
        }

        // The store order IS the policy: file first so a developer's edit lands
        // in git, database only when the filesystem refuses. Passed explicitly
        // rather than through a tag — a tagged iterator would let any module
        // reorder where an operator's config is kept just by existing, and the
        // glob-override trap makes a tagged argument easy to lose silently.
        //
        // ⚠️ Registered under a NAMED id, not its FQCN, and that is required
        // rather than stylistic. The class is excluded from the App\ glob (it
        // cannot be autowired), and an excluded class still gets an ABSTRACT
        // definition under its own FQCN — which an alias cannot point at. The
        // other exclusions in services.yaml survive for the same reason: every
        // one of them is registered under an id of its own.
        $container->register('coolms.core.config_writer', ChainedConfigWriter::class)
            ->setArgument('$stores', [
                new Reference(FileConfigWriter::class),
                new Reference(DbConfigWriter::class),
            ])
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(false);

        $container->setAlias(ConfigLoaderInterface::class, ChainedConfigLoader::class);
        $container->setAlias(ConfigWriterInterface::class, 'coolms.core.config_writer');

        $container->register(ConfigCacheWarmer::class)
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setPublic(false)
            ->addTag('kernel.cache_warmer');

        // File-format loaders -- available to any module via the coolms.file_format_loader tag.
        foreach ([YamlFileLoader::class, XmlFileLoader::class, PhpFileLoader::class] as $class) {
            $container->autowire($class)
                ->addTag('coolms.file_format_loader')
                ->setPublic(false);
        }

        // Phase Entity-Extract — the `EntityAliasRegistryInterface
        // → ClassMetaEntityAliasRegistry` alias moved to
        // `Entity\Infrastructure\DependencyInjection\Extension`
        // alongside the moved interface + implementation.
    }

    public function registerServices(ContainerBuilder $container): void
    {
        // Platform-wide registry for UI component configs (type: component in YAML).
        // Public so FormBundle::boot() can fetch and populate it via the scanner.
        $container->register(ComponentRegistry::class)
            ->setAutowired(false)
            ->setAutoconfigured(false)
            ->setPublic(true);

        // DiscriminatorValueSubscriber is registered automatically via the App\ resource
        // scan + autoconfigure=true (reads #[AsDoctrineListener] on the class).
        // No explicit registration needed here.

        $container->register(AlreadyInstantiatedObjectDenormalizer::class)
            ->addTag('serializer.denormalizer', ['priority' => 100]);

        $container->register(DateTimeObjectDenormalizer::class)
            ->addTag('serializer.normalizer', ['priority' => 100]);

        // F7 -- the transactional-outbox append port. The concrete
        // PersistingOutboxAppender is registered + made public by the App\ glob
        // (#[Autoconfigure(public: true)]) so it survives before any producer
        // consumes the port; here we only alias the L0 contract to it. The alias
        // stays private and is pruned-as-unused until the first producer migrates
        // onto the outbox -- by design, mirroring the analytics sink.
        // F7 relay side (the read/publish half of the outbox). The DBAL claim
        // repo + the in-process event publisher are glob-autowired; the relay
        // service + `coolms:outbox:relay` command consume them, so these aliases
        // are NOT pruned (no public flag needed).
        $container->setAlias(OutboxPublisherInterface::class, DispatchingOutboxPublisher::class)
            ->setPublic(false);

        // F7 §2 — consumer idempotency store. The concrete is glob-autowired +
        // #[Autoconfigure(public: true)] (resolvable before the first idempotent
        // consumer wires the port); the alias stays private + pruned-until-then.
        // F7 retention windows for `coolms:outbox:prune` (read via #[Autowire]).
        // Delivered outbox rows are done → a short window; the inbox window MUST
        // stay longer than the longest redelivery horizon (a late replay must
        // still hit a dedupe row). <1 disables that table's prune.
        $container->setParameter('coolms_core.outbox.published_retention_days', 7);
        $container->setParameter('coolms_core.inbox.processed_retention_days', 30);

        // Platform JSONC decoder seam (extracted from BpmnLiteJsonParser).
        // First consumer is the M2.c BPMN-Lite parser; future consumers
        // (M2.o conformance corpus, config loaders that want inline
        // annotation) inject the interface so the strip+decode pipeline
        // stays a single concern.
        $container->register(JsoncDecoder::class)
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setPublic(false);
        $container->setAlias(JsoncDecoderInterface::class, JsoncDecoder::class);

        // Definition-level label translation seam. F5.b Phase 2.
        // Reads `#[Translatable]`-annotated classes (FieldDefinition,
        // NaviNode, SiteSection, etc.) and routes through Symfony's
        // translator, which is decorated by `VfsOverlayingTranslator`
        // so VFS XLIFF overrides win over bundled `.xlf` files. The
        // resolver itself stays in `CoolMS\CoreModule\Translation\`;
        // only the alias is wired here.
        $container->setAlias(LabelResolverInterface::class, LabelResolver::class);
    }

    public function getAlias(): string
    {
        return 'core';
    }
}
