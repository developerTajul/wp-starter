<?php
declare(strict_types=1);

namespace Framework\Console;

abstract class Command
{
    /**
     * The WP-CLI command name, e.g. "mini-laravel migrate".
     */
    abstract public function getName(): string;

    /**
     * Command entry point.
     *
     * WP-CLI passes positional arguments in $args and `--flag=value` style
     * arguments in $assocArgs; both are raw strings.
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assocArgs
     */
    abstract public function handle(array $args, array $assocArgs): void;

    protected function success(string $message): void
    {
        // The guard keeps commands callable outside WP-CLI (tests, a cron
        // context, a direct include), where \WP_CLI does not exist.
        if (class_exists('WP_CLI')) {
            \WP_CLI::success($message);
        }
    }

    protected function error(string $message): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::error($message);
        }
    }

    protected function info(string $message): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::log($message);
        }
    }
}
