<?php
declare(strict_types=1);

return [
    'name' => 'WP Mini Laravel',
    'env' => 'development', // 'development' or 'production'
    'prefix' => 'wp_mini_laravel_', // Useful for options, transients, etc.

    // Root namespace for generated/application classes. Consuming projects
    // override this with their own (e.g. 'LeadBridge').
    'namespace' => 'App',

    // Namespace for WP-CLI commands, so two projects bundling this framework
    // do not register commands under the same name.
    'cli_prefix' => 'wp_mini',

    'providers' => [
        // Service Providers list will go here (e.g., AppServiceProvider, RouteServiceProvider)
        \App\Providers\AppServiceProvider::class,
    ]
];