<?php
declare(strict_types=1);

namespace Framework\Console;

/**
 * Base class for every WP-CLI command in the framework.
 *
 * Output goes through the protected helpers rather than \WP_CLI directly for two
 * reasons: they guard against \WP_CLI being absent (the command is runnable from
 * a test, a cron context, or a direct include), and they keep a command's own
 * code limited to what the command *does* rather than how it reports.
 *
 * This class deliberately does not extend \WP_CLI_Command. WP-CLI accepts a
 * plain callable — which is exactly what the kernel registers — so extending it
 * would couple every command to WP-CLI being loaded in order to be *declared*,
 * not merely to be run.
 */
abstract class Command
{
    /**
     * The command name as registered with WP-CLI, including its namespace,
     * e.g. "wp_mini make:provider".
     */
    abstract public function getName(): string;

    /**
     * Short description shown by `wp help <name>`.
     */
    abstract public function getDescription(): string;

    /**
     * Run the command.
     *
     * WP-CLI passes positional arguments in $args and `--flag=value` style
     * arguments in $assocArgs; both arrive as raw strings and are the command's
     * job to validate.
     *
     * @param array<int, string>    $args      Positional arguments.
     * @param array<string, string> $assocArgs Associative arguments.
     */
    abstract public function handle(array $args, array $assocArgs): void;

    protected function success(string $message): void
    {
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

    protected function warning(string $message): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::warning($message);
        }
    }
}
