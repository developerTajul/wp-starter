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

    /**
     * The spellings of "no" a boolean flag may arrive as.
     *
     * WP-CLI hands `--flag=value` through as a raw string and leaves a bare
     * `--flag` as boolean `true`, so a command has to decide what the string
     * means. Anything in this list is false; anything else non-empty ("1",
     * "true", "yes", "on", or any other word) is true.
     *
     * The list is a single constant because it was previously copied into four
     * commands, and they drifted: `migrate` refused `false`/`no`/`0` but read
     * `off` as *yes*, while `migrate:fresh`'s `--force` check accepted `off` as
     * *no*. The same spelling therefore meant opposite things in two commands,
     * and in `--pretend`'s case the wrong reading is the dangerous one — a CI
     * variable spelled `PRETEND=off` silently turned a real migration into a dry
     * run that reported success.
     *
     * A flag that destroys data must not be enabled by a value its operator meant
     * as "no", so this list errs toward refusing rather than assuming.
     */
    protected const FALSY_FLAG_VALUES = ['', '0', 'false', 'no', 'off', 'null', 'nil', 'none'];

    /**
     * Whether an associative WP-CLI argument was passed as a true flag.
     *
     * Returns false when the key is absent. A bare `--flag` arrives as boolean
     * `true` and is returned as-is, because casting it to a string would produce
     * `"1"` — which happens to be correct, but only by accident. A string is
     * trimmed, lowercased and compared against {@see self::FALSY_FLAG_VALUES}.
     *
     * @param array<string, mixed> $assocArgs
     */
    protected function flagIsTrue(array $assocArgs, string $name): bool
    {
        if (!array_key_exists($name, $assocArgs)) {
            return false;
        }

        $value = $assocArgs[$name];

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return !in_array(strtolower(trim((string) $value)), self::FALSY_FLAG_VALUES, true);
    }

    /**
     * Whether a keyed option carries a usable value, as opposed to a bare flag.
     *
     * `--batch=all` is an option with a value; a bare `--batch` arrives as boolean
     * `true` and carries none. Callers use this to fall back to a default rather
     * than reading `"1"` out of a boolean.
     *
     * Named `optionValue` rather than `flagValue` because {@see GeneratorCommand}
     * already has a `flagValue()` reading a single flag name off `$this->assocArgs`,
     * and a base-class method of that name would collide with it.
     *
     * @param array<string, mixed> $assocArgs
     */
    protected function optionValue(array $assocArgs, string $name): ?string
    {
        if (!array_key_exists($name, $assocArgs)) {
            return null;
        }

        $value = $assocArgs[$name];

        if (is_bool($value) || is_array($value) || $value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
