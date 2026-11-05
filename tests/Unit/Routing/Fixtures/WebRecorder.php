<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * Where the web routing fixture controllers record what happened to them.
 *
 * A static rather than an instance property, because the container builds a fresh
 * controller per dispatch: an instance property would be written once and read by
 * nobody, which is the trap that makes a controller test pass for the wrong
 * reason — the assertion sees an empty array whether or not the action ran.
 */
final class WebRecorder
{
    /**
     * Actions that ran, in order, as `Class@action`.
     *
     * @var list<string>
     */
    public static array $calls = [];

    /**
     * Parameters the actions received, by name.
     *
     * @var array<string, mixed>
     */
    public static array $received = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$received = [];
    }
}
