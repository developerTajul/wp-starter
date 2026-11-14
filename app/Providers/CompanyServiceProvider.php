<?php

declare(strict_types=1);

namespace App\Providers;

use Core\Company\Application\Contracts\CompanyCommandServiceContract;
use Core\Company\Application\Contracts\CompanyMapperContract;
use Core\Company\Application\Contracts\CompanyQueryContract;
use Core\Company\Application\Contracts\CompanyQueryServiceContract;
use Core\Company\Application\Contracts\CompanyResponseMapperContract;
use Core\Company\Application\Mappers\CompanyMapper;
use Core\Company\Application\Mappers\CompanyResponseMapper;
use Core\Company\Application\Services\CompanyCommandService;
use Core\Company\Application\Services\CompanyQueryService;
use Core\Company\Domain\Contracts\CompanyRepositoryContract;
use Core\Company\Infrastructure\Mappers\CompanyEntityMapper;
use Core\Company\Infrastructure\Repositories\WpCompanyReadRepository;
use Core\Company\Infrastructure\Repositories\WpCompanyWriteRepository;
use DevLinkStudios\WPServiceContainer\ServiceProvider;

/**
 * Container bindings for the company module.
 *
 * Every binding the module owns lives here rather than in
 * {@see AppServiceProvider}, which holds shared-kernel bindings only. Each
 * contract has exactly one implementation: the `Wp*` classes under
 * `core/Company/Infrastructure/Repositories/`, which use the Fluent Database
 * builder.
 */
final class CompanyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerInfrastructure();
        $this->registerServices();
    }

    /**
     * The module's adapters and mappers.
     */
    private function registerInfrastructure(): void
    {
        // The entity mapper is stateless and holds no handle, so one instance
        // serves every repository and every request.
        $this->container->singleton(
            CompanyEntityMapper::class,
            static fn (): CompanyEntityMapper => new CompanyEntityMapper()
        );

        // Bound by concrete class so the builder and the mapper arrive by
        // injection. The query builder itself is bound by
        // Framework\Database\QueryServiceProvider; resolving it here rather
        // than constructing it keeps the connection out of this file.
        $this->container->bind(
            WpCompanyWriteRepository::class,
            fn ($container): WpCompanyWriteRepository => new WpCompanyWriteRepository(
                $container->resolve(\Framework\Database\Query\QueryBuilderInterface::class),
                $container->resolve(CompanyEntityMapper::class)
            )
        );

        // The read repository is bound separately from the write one: the two
        // implement different contracts and neither knows about the other. It
        // takes the same builder and mapper, and the connection is resolved
        // lazily inside the builder, which is what lets this binding be
        // registered before WordPress has loaded the database.
        $this->container->bind(
            WpCompanyReadRepository::class,
            fn ($container): WpCompanyReadRepository => new WpCompanyReadRepository(
                $container->resolve(\Framework\Database\Query\QueryBuilderInterface::class),
                $container->resolve(CompanyEntityMapper::class)
            )
        );

        // Bound by interface as well, because the services type-hint the
        // contracts rather than the concrete classes.
        $this->container->bind(
            CompanyRepositoryContract::class,
            fn ($container): CompanyRepositoryContract => $container->resolve(
                WpCompanyWriteRepository::class
            )
        );

        $this->container->bind(
            CompanyQueryContract::class,
            fn ($container): CompanyQueryContract => $container->resolve(
                WpCompanyReadRepository::class
            )
        );
    }

    /**
     * The module's mappers and services, bound by their inbound contracts.
     */
    private function registerServices(): void
    {
        // The DTO-to-entity mapper and the entity-to-response mapper are both
        // stateless, so a shared instance is safe.
        $this->container->singleton(
            CompanyMapperContract::class,
            static fn (): CompanyMapperContract => new CompanyMapper()
        );

        $this->container->singleton(
            CompanyResponseMapperContract::class,
            static fn (): CompanyResponseMapperContract => new CompanyResponseMapper()
        );

        $this->container->bind(
            CompanyCommandServiceContract::class,
            fn ($container): CompanyCommandServiceContract => new CompanyCommandService(
                $container->resolve(CompanyRepositoryContract::class),
                $container->resolve(CompanyResponseMapperContract::class),
                $container->resolve(\Core\Shared\Domain\Contracts\TransactionManagerContract::class),
                $container->resolve(\Core\Shared\Domain\Contracts\TranslatorContract::class),
                $container->resolve(\Psr\Log\LoggerInterface::class)
            )
        );

        $this->container->bind(
            CompanyQueryServiceContract::class,
            fn ($container): CompanyQueryServiceContract => new CompanyQueryService(
                $container->resolve(CompanyQueryContract::class),
                $container->resolve(CompanyResponseMapperContract::class),
                $container->resolve(\Core\Shared\Domain\Contracts\TranslatorContract::class)
            )
        );
    }
}
