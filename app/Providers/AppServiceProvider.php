<?php
declare(strict_types=1);

namespace App\Providers;

use DevLinkStudios\WPServiceContainer\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // $this->container->bind(SomeInterface::class, SomeClass::class);
    }

    public function boot(): void
    {
        // add_action('init', [$this, 'someMethod']);
    }
}