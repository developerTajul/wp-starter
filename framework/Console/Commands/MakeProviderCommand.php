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
        // A bare name rather than a path: resolution goes through StubLocator,
        // which prefers a project copy and falls back to the framework's own
        // directory — never a hardcoded WordPress constant, and never a path
        // that assumes the framework sits inside the project.
        return 'provider.stub';
    }

    protected function getTargetPath(string $className): string
    {
        // `Admin/BlogProvider` lands in app/Providers/Admin/, matching the
        // namespace the base class appends. See subDirectoryPath().
        return app_path('Providers/' . $this->subDirectoryPath() . $className . '.php');
    }

    protected function getPostWriteHint(): ?string
    {
        return "Remember to register it in config/app.php under 'providers'.";
    }
}
