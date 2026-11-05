<?php
declare(strict_types=1);

namespace Framework\Console;

use DevLinkStudios\WPServiceContainer\Container;

/**
 * Registers the application's commands with WP-CLI.
 *
 * The framework owns this machinery; which commands exist is a per-project
 * decision, expressed by a subclass declaring $commands. See App\Console\Kernel.
 */
abstract class Kernel
{
    /**
     * Fully-qualified class names of the commands to register.
     *
     * @var array<int, class-string<Command>>
     */
    protected array $commands = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return array<int, class-string<Command>>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Resolve every registered command and hand it to WP-CLI.
     *
     * No-op when WP-CLI is not loaded: under a web request there is nothing to
     * register with, and \WP_CLI::add_command() would fatal.
     */
    public function bootstrap(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        foreach ($this->commands as $commandClass) {
            // Resolve through the container rather than `new`, so a command can
            // declare its own constructor dependencies and have them injected.
            /** @var Command $instance */
            $instance = $this->container->resolve($commandClass);

            \WP_CLI::add_command(
                $instance->getName(),
                [$instance, 'handle'],
                ['shortdesc' => $instance->getDescription()]
            );
        }
    }
}
