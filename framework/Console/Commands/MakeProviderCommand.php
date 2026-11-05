<?php
declare(strict_types=1);

namespace Framework\Console\Commands;

use Framework\Config\Config;
use Framework\Console\GeneratorCommand;

class MakeProviderCommand extends GeneratorCommand
{
    public function getName(): string
    {
        // Read dynamically so a consuming project can rename the CLI namespace
        // without touching framework code.
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' make:provider';
    }

    public function getDescription(): string
    {
        return 'Create a new service provider class.';
    }

    protected function getType(): string
    {
        return 'Provider';
    }

    protected function getNamespace(): string
    {
        // Providers live in a namespace of their own, so this never returns
        // bare config('app.namespace') the way the base class does.
        return config('app.namespace', Config::DEFAULT_NAMESPACE) . '\\Providers';
    }

    protected function getStubPath(): string
    {
        // Paths come from the container-bound base path, never a hardcoded
        // WordPress constant, so the framework works outside wp-content.
        return base_path('stubs/provider.stub');
    }

    protected function getTargetPath(string $className): string
    {
        return app_path('Providers/' . $className . '.php');
    }

    protected function getPostWriteHint(): ?string
    {
        return "Remember to register it in config/app.php under 'providers'.";
    }
}
