<?php
declare(strict_types=1);

namespace App;

use DevLinkStudios\WPServiceContainer\Container;

class Kernel
{
    protected Container $app;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    public function bootstrap(): void
    {
        $providers = config('app.providers', []);

        foreach ($providers as $providerClass) {
            // A typo in config/app.php must degrade one provider, not the whole
            // site: an uncaught throwable here escapes during plugin load.
            try {
                if (!class_exists($providerClass)) {
                    throw new \Exception(
                        sprintf('Service provider class [%s] does not exist.', $providerClass)
                    );
                }

                // Let the container resolve dependencies and register the provider
                $this->app->register($providerClass);
            } catch (\Throwable $e) {
                error_log("WP Mini Laravel Boot Error: " . $e->getMessage());
            }
        }

        // Console runs last: commands resolve through the container, so the
        // providers above must have registered their bindings first. The App
        // kernel carries the project's command registry; the guard lets the
        // framework boot in projects that ship no console kernel at all.
        if (class_exists(\App\Console\Kernel::class)) {
            (new \App\Console\Kernel($this->app))->bootstrap();
        }
    }
}