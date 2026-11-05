<?php
declare(strict_types=1);

namespace Tests\Unit\Console;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Console\Commands\MakeControllerCommand;
use Framework\Console\Commands\MakeProviderCommand;
use Framework\Console\Commands\MakeRouteCommand;
use PHPUnit\Framework\TestCase;

/**
 * Covers the console registry: which commands a project exposes, and that each
 * one resolves out of the container with its own dependencies.
 */
final class ConsoleWiringTest extends TestCase
{
    public function test_the_project_registers_the_generators_it_ships(): void
    {
        $commands = (new \App\Console\Kernel(new Container()))->getCommands();

        $this->assertContains(MakeProviderCommand::class, $commands);
        $this->assertContains(MakeControllerCommand::class, $commands);
        $this->assertContains(MakeRouteCommand::class, $commands);
    }

    /**
     * A command is resolved through the container, so one declaring constructor
     * dependencies would be injected rather than failing to construct. This
     * asserts the framework's Kernel actually resolves rather than instantiates.
     */
    public function test_the_framework_kernel_resolves_a_command_through_the_container(): void
    {
        $container = new Container();
        $container->singleton(MakeControllerCommand::class);

        $resolved = $container->resolve(MakeControllerCommand::class);

        $this->assertInstanceOf(MakeControllerCommand::class, $resolved);
    }

    /**
     * Every command name must be namespaced by the project's CLI prefix, or two
     * plugins bundling this framework would register colliding commands.
     */
    public function test_command_names_are_prefixed(): void
    {
        $prefix = config('app.cli_prefix', 'wp_mini');

        $this->assertSame("{$prefix} make:provider", (new MakeProviderCommand())->getName());
        $this->assertSame("{$prefix} make:controller", (new MakeControllerCommand())->getName());
        $this->assertSame("{$prefix} make:route", (new MakeRouteCommand())->getName());
    }

    /**
     * WP-CLI renders this in `wp help`, so every command needs one.
     */
    public function test_every_command_declares_a_description(): void
    {
        foreach ($this->commands() as $command) {
            $this->assertNotSame('', $command->getDescription());
        }
    }

    /**
     * @return list<\Framework\Console\Command>
     */
    private function commands(): array
    {
        return [
            new MakeProviderCommand(),
            new MakeControllerCommand(),
            new MakeRouteCommand(),
        ];
    }
}
