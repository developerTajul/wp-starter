<?php
declare(strict_types=1);

namespace Framework\Console\Commands;

use Framework\Config\Config;
use Framework\Console\GeneratorCommand;

/**
 * Creates the routes file for a project that does not have one yet.
 *
 * There is only ever one routes file, so this is a scaffold rather than a
 * generator for arbitrary names — the name argument is accepted but the target
 * path is fixed. Re-running it is reported as an error by the base class rather
 * than silently overwriting hand-written routes.
 */
class MakeRouteCommand extends GeneratorCommand
{
    private const ROUTES_FILE = 'api.php';

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' make:route';
    }

    public function getDescription(): string
    {
        return 'Create the REST routes file at routes/api.php.';
    }

    protected function getType(): string
    {
        return 'Routes file';
    }

    protected function getStubPath(): string
    {
        // Bare name: see StubLocator. The target path below is still the
        // project's — only the *template* is overridable.
        return 'routes.stub';
    }

    protected function getTargetPath(string $className): string
    {
        return base_path('routes/' . self::ROUTES_FILE);
    }

    /**
     * The file name is fixed, so no class name is accepted — passing one is more
     * likely to be a misunderstanding than a request, and silently ignoring it
     * would leave the caller thinking a differently-named file was created.
     */
    public function handle(array $args, array $assocArgs): void
    {
        parent::handle([self::ROUTES_FILE], $assocArgs);
    }

    protected function resolveClassName(?string $name): ?string
    {
        return self::ROUTES_FILE;
    }

    protected function getNamespace(): string
    {
        return (string) config('app.namespace', Config::DEFAULT_NAMESPACE);
    }

    protected function getPostWriteHint(): ?string
    {
        return 'It is loaded automatically by RouteServiceProvider on rest_api_init.';
    }
}
