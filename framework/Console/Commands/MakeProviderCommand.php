<?php
declare(strict_types=1);

namespace Framework\Console\Commands;

use Framework\Console\Command;

class MakeProviderCommand extends Command
{
    public function getName(): string
    {
        // Read dynamically so a consuming project can rename the CLI namespace
        // without touching framework code.
        $prefix = config('app.cli_prefix', 'wp_mini');

        return "{$prefix} make:provider";
    }

    public function handle(array $args, array $assocArgs): void
    {
        $name = $args[0] ?? null;

        if ($name === null || trim($name) === '') {
            $this->error('Please provide a provider name. Example: ' . $this->getName() . ' LeadBridge');
            return;
        }

        // Class names cannot carry path separators or a .php suffix; strip both
        // so `make:provider Providers/Foo.php` still lands on a valid class.
        $name = str_replace(['\\', '/'], '', $name);
        $name = preg_replace('/\.php$/i', '', $name);

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            $this->error("Invalid provider name [{$name}]. Use letters, numbers and underscores, starting with a letter.");
            return;
        }

        // Paths come from the container-bound base path, never a hardcoded
        // WordPress constant, so the framework works outside wp-content.
        $stubPath = base_path('stubs/provider.stub');
        $targetPath = app_path('Providers/' . $name . '.php');

        if (!file_exists($stubPath)) {
            $this->error("Stub file not found at: {$stubPath}");
            return;
        }

        if (file_exists($targetPath)) {
            $this->error("Provider already exists at: {$targetPath}");
            return;
        }

        $namespace = config('app.namespace', 'App') . '\\Providers';

        $contents = file_get_contents($stubPath);

        if ($contents === false) {
            $this->error("Unable to read stub file at: {$stubPath}");
            return;
        }

        $contents = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $name],
            $contents
        );

        // wp_mkdir_p() is a WordPress function, so fall back to mkdir() when the
        // command is exercised outside a loaded WordPress (e.g. in tests).
        $directory = dirname($targetPath);

        if (!is_dir($directory)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($directory);
            } else {
                mkdir($directory, 0755, true);
            }
        }

        if (file_put_contents($targetPath, $contents) === false) {
            $this->error("Unable to write provider to: {$targetPath}");
            return;
        }

        // wp_normalize_path() renders clean forward slashes on Windows; the raw
        // path is the correct fallback when WordPress is not loaded.
        $displayPath = function_exists('wp_normalize_path')
            ? wp_normalize_path($targetPath)
            : $targetPath;

        $this->success("Provider created successfully: {$displayPath}");
        $this->info("Remember to register it in config/app.php under 'providers'.");
    }
}
