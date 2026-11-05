<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimal WordPress function surface, defined once per PHP process.
 *
 * The framework's WordPress-facing code is guarded by function_exists() and
 * class_exists() checks, so it can be exercised without a WordPress install —
 * but only if something defines those functions. This class loads them.
 *
 * It is a plain function-defining file rather than a PHPUnit extension because
 * PHP cannot undeclare a function: the stubs must exist for the whole process,
 * and tests that need different behaviour change state through the recorders
 * below rather than by redefining anything.
 *
 * Loaded from tests/bootstrap.php, before the autoloader's own side effects.
 */
final class WpStub
{
    /**
     * Hooks registered through add_action(), as tag => list of callbacks.
     *
     * @var array<string, list<callable>>
     */
    public static array $actions = [];

    /**
     * Routes handed to register_rest_route(), as namespace => list of args.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public static array $registeredRoutes = [];

    /**
     * The response of the next current_user_can() call, or the default.
     */
    public static bool $currentUserCan = true;

    public static bool $userLoggedIn = false;

    /**
     * @var array<string, mixed>
     */
    public static array $options = [];

    public static function reset(): void
    {
        self::$actions = [];
        self::$registeredRoutes = [];
        self::$currentUserCan = true;
        self::$userLoggedIn = false;
        self::$options = [];
    }

    /**
     * Fire every callback registered for a hook, as WordPress would.
     */
    public static function doAction(string $tag, mixed ...$args): void
    {
        foreach (self::$actions[$tag] ?? [] as $callback) {
            $callback(...$args);
        }
    }

    /**
     * Callbacks registered for a hook.
     *
     * @return list<callable>
     */
    public static function actionsFor(string $tag): array
    {
        return self::$actions[$tag] ?? [];
    }
}
