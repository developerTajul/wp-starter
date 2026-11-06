<?php
declare(strict_types=1);

namespace Framework\Console\Commands;

use Framework\Config\Config;
use Framework\Console\GeneratorCommand;

class MakeControllerCommand extends GeneratorCommand
{
    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' make:controller';
    }

    public function getDescription(): string
    {
        return 'Create a new REST controller class.';
    }

    protected function getType(): string
    {
        return 'Controller';
    }

    protected function getNamespace(): string
    {
        return config('app.namespace', Config::DEFAULT_NAMESPACE) . '\\Http\\Controllers';
    }

    protected function getStubPath(): string
    {
        // A bare name, not a path: StubLocator checks the project's own
        // stubs/controller.stub first and falls back to the framework's, which is
        // what keeps this working when the framework is installed under vendor/.
        return 'controller.stub';
    }

    protected function getTargetPath(string $className): string
    {
        return app_path('Http/Controllers/' . $className . '.php');
    }

    protected function getPostWriteHint(): ?string
    {
        // Named relative to the plugin root, not spelled with an absolute
        // config_path() — the path is the same on every install and the
        // absolute form is a machine-specific path in a user-facing message.
        return 'It registers its own routes through #[Route] attributes; add it to routes/api.php to mount it.';
    }
}
