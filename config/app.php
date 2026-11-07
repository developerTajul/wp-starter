<?php
declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'WP Mini Laravel'),
    'env' => env('APP_ENV', 'production'),

    // Prepended to every option, transient, meta key and nonce action the
    // plugin creates, so two installs sharing a database cannot collide.
    'prefix' => env('APP_PREFIX', 'wp_mini_laravel_'),

    // Root namespace for generated/application classes. Consuming projects
    // override this with their own (e.g. 'LeadBridge').
    'namespace' => env('APP_NAMESPACE', 'App'),

    // Where view names resolve from, relative to the plugin root or absolute.
    // A relative path is the normal case: an absolute one in a config file is a
    // path that is wrong on every other machine, and the repo is what gets
    // shared. Resolution is dotted — 'leads.index' -> views/leads/index.php.
    'views' => env('APP_VIEWS_PATH', 'views'),

    // Namespace for WP-CLI commands, so two projects bundling this framework
    // do not register commands under the same name.
    'cli_prefix' => env('CLI_PREFIX', 'wp_mini'),

    // Whether an uninstall is allowed to delete the plugin's data, keyed as
    // `prefix . 'delete_data_on_uninstall'`. The default is false, which is the
    // only safe default for a destructive action: a site that never touched the
    // setting keeps every table when the plugin is deleted.
    //
    // Note this is the *default*; the setting itself is a WordPress option, so a
    // site owner can flip it at any time and the value read at uninstall is the
    // one that counts. See `App\Lifecycle\PluginLifecycle`.
    'delete_data_on_uninstall' => env('DELETE_DATA_ON_UNINSTALL', false),

    // The REST namespace every route is published under. WordPress exposes it
    // at /wp-json/{rest_namespace}/ and uses it to scope route discovery, so it
    // must be unique per plugin — two plugins sharing one would have their
    // route tables merged and their `permission_callback`s both consulted.
    'rest_namespace' => env('REST_NAMESPACE', 'wp-mini-laravel/v1'),

    'providers' => [
        \App\Providers\AppServiceProvider::class,

        // Binds Core\Note\Domain\NoteRepositoryContract to its $wpdb-backed
        // adapter, which is what lets the container build NoteService. Listed
        // before the routing providers so a route that resolves the service
        // during boot finds the binding already in place.
        \App\Providers\RepositoryServiceProvider::class,

        \App\Providers\RouteServiceProvider::class,
        \Framework\Database\MigrationServiceProvider::class,

        // After the migration provider, whose `Migrator` and
        // `MigrationStorageInterface` bindings it composes. Listed here rather
        // than before it so the file reads in dependency order; the bindings are
        // lazy, so the order is not load-bearing.
        \App\Providers\LifecycleServiceProvider::class,

        // Binds Framework\Http\Request (captured from the superglobals) and the
        // middleware Pipeline, so both can be type-hinted anywhere a service is
        // resolved. Listed last: it captures the request, and nothing else needs
        // to run before that happens.
        \Framework\Http\HttpServiceProvider::class,
    ],
];
