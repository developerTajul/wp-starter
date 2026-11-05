<?php
declare(strict_types=1);

return [
    'name' => 'WP Mini Laravel',
    'env' => 'development', // 'development' or 'production'

    // Prepended to every option, transient, meta key and nonce action the
    // plugin creates, so two installs sharing a database cannot collide.
    'prefix' => 'wp_mini_laravel_',

    // Root namespace for generated/application classes. Consuming projects
    // override this with their own (e.g. 'LeadBridge').
    'namespace' => 'App',

    // Namespace for WP-CLI commands, so two projects bundling this framework
    // do not register commands under the same name.
    'cli_prefix' => 'wp_mini',

    // The REST namespace every route is published under. WordPress exposes it
    // at /wp-json/{rest_namespace}/ and uses it to scope route discovery, so it
    // must be unique per plugin — two plugins sharing one would have their
    // route tables merged and their `permission_callback`s both consulted.
    'rest_namespace' => 'wp-mini-laravel/v1',

    'providers' => [
        \App\Providers\AppServiceProvider::class,
        \App\Providers\RouteServiceProvider::class,
        \Framework\Database\MigrationServiceProvider::class,

        // Binds Framework\Http\Request (captured from the superglobals) and the
        // middleware Pipeline, so both can be type-hinted anywhere a service is
        // resolved. Listed last: it captures the request, and nothing else needs
        // to run before that happens.
        \Framework\Http\HttpServiceProvider::class,
    ],
];
