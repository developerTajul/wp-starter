<?php
declare(strict_types=1);

namespace Framework\Console;

use DevLinkStudios\WPServiceContainer\Container;

class Kernel
{
    protected Container $app;

    /**
     * Fully-qualified class names of the commands to register with WP-CLI.
     *
     * @var array<int, class-string<Command>>
     */
    protected array $commands = [];

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    public function bootstrap(): void
    {
        // Under a web request — or any context without WP-CLI loaded — there is
        // nothing to register with, and \WP_CLI::add_command() would fatal.
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        foreach ($this->commands as $commandClass) {
            // resolve() auto-wires the command and honours any container
            // binding, so a command can declare its own dependencies.
            $instance = $this->app->resolve($commandClass);

            \WP_CLI::add_command($instance->getName(), [$instance, 'handle']);
        }
    }
}
