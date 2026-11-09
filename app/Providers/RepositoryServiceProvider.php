<?php

declare(strict_types=1);

namespace App\Providers;

use App\Cache\WpCacheStore;
use App\Database\AtomicDatabaseRateLimiter;
use App\Database\WpTransactionManager;
use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Domain\Contracts\LeadRepositoryContract;
use Core\Lead\Infrastructure\Mappers\LeadEntityMapper;
use Core\Shared\Domain\Contracts\CacheInterface;
use Core\Shared\Domain\Contracts\RateLimiterContract;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Core\Shared\Infrastructure\Localization\WpTranslator;
use Core\Shared\Infrastructure\Logging\WpLogger;
use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Framework\Config\Config;
use Framework\Database\Query\QueryBuilderInterface;
use Psr\Log\LoggerInterface;

/**
 * Container bindings for the lead module's adapters and shared infrastructure.
 */
final class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Single instance so the nesting depth counter is shared. A fresh
        // instance per resolve would treat each nested call as the outermost
        // transaction and issue START TRANSACTION inside the outer one.
        $this->container->singleton(
            TransactionManagerContract::class,
            static fn (): WpTransactionManager => new WpTransactionManager()
        );

        // Rate limiter adapter. Non‑shared, matching the repositories: it
        // holds a $wpdb handle and nothing else, so a per-request binding
        // avoids capturing one request's handle in a long-lived instance.
        $this->container->bind(
            RateLimiterContract::class,
            static fn (): AtomicDatabaseRateLimiter => new AtomicDatabaseRateLimiter()
        );

        $this->registerLeadRepository();
        $this->registerTranslator();
        $this->registerLogger();
    }

    /**
     * Bind the translator the Core services read wording through.
     *
     * `lang/` sits at the plugin root, so the path is the plugin directory
     * rather than the application's. The framework's `base_path()` helper is
     * not used because it may not be defined while providers register.
     */
    private function registerTranslator(): void
    {
        $this->container->singleton(
            TranslatorContract::class,
            static fn (): TranslatorContract => new WpTranslator(
                dirname(__DIR__, 2) . '/lang'
            )
        );
    }

    /**
     * Bind the PSR-3 logger Core services report failures through.
     *
     * The threshold comes from config rather than `env()`, because environment
     * variables are read once at config load and nowhere else.
     */
    private function registerLogger(): void
    {
        $this->container->singleton(
            LoggerInterface::class,
            static fn (): LoggerInterface => new WpLogger(
                (string) config('app.log_level', 'error')
            )
        );
    }

    /**
     * Bind the lead repositories and cache store.
     */
    private function registerLeadRepository(): void
    {
        // Write repository, bound by concrete class. Takes the mapper and an
        // optional $wpdb; the container builds it and the handle is resolved
        // lazily inside.
        $this->container->bind(
            \Core\Lead\Infrastructure\Repositories\LeadWriteRepository::class,
            static fn (): \Core\Lead\Infrastructure\Repositories\LeadWriteRepository
                => new \Core\Lead\Infrastructure\Repositories\LeadWriteRepository(new LeadEntityMapper())
        );

        // Read repository, bound by concrete class. Takes the fluent builder and
        // the mapper; the builder resolves the connection lazily inside, so
        // constructing this during register() is safe.
        $this->container->bind(
            \Core\Lead\Infrastructure\Repositories\LeadReadRepository::class,
            fn ($container): \Core\Lead\Infrastructure\Repositories\LeadReadRepository
                => new \Core\Lead\Infrastructure\Repositories\LeadReadRepository(
                    $container->resolve(QueryBuilderInterface::class),
                    new LeadEntityMapper()
                )
        );

        // Bound by interface as well, because the services type-hint the
        // contracts rather than the concrete classes.
        $this->container->bind(
            LeadRepositoryContract::class,
            fn ($container): LeadRepositoryContract => $container->resolve(
                \Core\Lead\Infrastructure\Repositories\LeadWriteRepository::class
            )
        );

        $this->container->bind(
            LeadQueryContract::class,
            fn ($container): LeadQueryContract => $container->resolve(
                \Core\Lead\Infrastructure\Repositories\LeadReadRepository::class
            )
        );

        $this->registerLeadCache();
    }

    /**
     * Bind the lead module's cache store.
     */
    private function registerLeadCache(): void
    {
        // Reads app.prefix via config() because the framework requires
        // configuration to be read through Config and passed in. Falls back
        // to the framework's DEFAULT_PREFIX because WpCacheStore refuses an
        // empty prefix; passing '' would turn "config not yet loaded" into a
        // hard failure at resolve time.
        $this->container->bind(
            CacheInterface::class,
            static fn (): CacheInterface => new WpCacheStore(
                (string) config('app.prefix', Config::DEFAULT_PREFIX)
            )
        );
    }
}
