<?php
declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'WP Mini Laravel'),
    'env' => env('APP_ENV', 'production'),

    // Show diagnostic detail for server errors when debug is true.
    'debug' => env('APP_DEBUG', false),

    // Prepended to every option, transient, meta key and nonce action, so two
    // installs sharing one database do not collide.
    'prefix' => env('APP_PREFIX', 'wp_mini_laravel_'),

    // Root namespace for generated classes; consuming projects override it with
    // their own (e.g. 'LeadBridge').
    'namespace' => env('APP_NAMESPACE', 'App'),

    // Where view names resolve from, relative to the plugin root or absolute.
    // Keep it relative: an absolute path in a config file is wrong on every
    // other machine. Resolution is dotted, 'leads.index' -> views/leads/index.php.
    'views' => env('APP_VIEWS_PATH', 'views'),

    // Namespace for WP-CLI commands, so two projects bundling this framework do
    // not register commands under the same name.
    'cli_prefix' => env('CLI_PREFIX', 'wp_mini'),

    // Default for the uninstall wipe, stored as `prefix . 'delete_data_on_uninstall'`.
    // Deleting the plugin is the consent, so the default is true: a false default
    // left the tables behind whenever nobody ticked the Reading screen checkbox.
    // This only seeds the option; the wipe reads the stored option at uninstall
    // time, so a site owner can still opt out.
    'delete_data_on_uninstall' => env('DELETE_DATA_ON_UNINSTALL', true),

    // The REST namespace every route is published under, exposed at
    // /wp-json/{rest_namespace}/. It must be unique per plugin: sharing one with
    // another plugin merges both route tables and consults both callbacks.
    'rest_namespace' => env('REST_NAMESPACE', 'wp-mini-laravel/v1'),

    'providers' => [
        \App\Providers\AppServiceProvider::class,

        // Binds the lead write and read ports to their $wpdb-backed adapters.
        // Listed before the routing providers so a service resolved during boot
        // finds the binding in place.
        \App\Providers\RepositoryServiceProvider::class,

        // Binds the company module's repositories, mappers and services. It
        // depends on the shared-kernel bindings above (transactions, translation
        // and logging), so it is listed after them.
        \App\Providers\CompanyServiceProvider::class,

        \App\Providers\RouteServiceProvider::class,
        \Framework\Database\MigrationServiceProvider::class,

        // Binds QueryBuilderInterface. Separate from the migration provider because
        // the migrator runs DDL and never builds a query, while a repository builds
        // queries and never migrates; merging them couples both consumers.
        \Framework\Database\QueryServiceProvider::class,

        // Binds the seeder registry and `db:seed`. Separate from the migration
        // provider: a migration transforms the schema once and is recorded; a
        // seeder makes data present and is re-runnable. The registry points at
        // `database/seeders/`.
        \Framework\Database\SeedingServiceProvider::class,

        // Composes the `Migrator` and `MigrationStorageInterface` bindings, so it
        // is listed after the migration provider. The bindings are lazy, so the
        // order is not load-bearing.
        \App\Providers\LifecycleServiceProvider::class,

        // Binds Framework\Http\Request, captured from the superglobals, and the
        // middleware Pipeline. Listed last: it captures the request, and nothing
        // before it depends on that happening first.
        \Framework\Http\HttpServiceProvider::class,
    ],
];
