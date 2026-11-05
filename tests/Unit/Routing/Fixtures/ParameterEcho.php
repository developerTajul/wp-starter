<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * Records the `id` it was given, on the REST path.
 *
 * A separate fixture from {@see ParameterController} because the two are reached
 * through different parameter sources, and sharing one recorder across both would
 * make a test that forgot to reset it pass on the previous test's value.
 */
final class ParameterEcho
{
    /** @var array<string, mixed> */
    public static array $received = [];

    public function show(string $id): string
    {
        self::$received['id'] = $id;

        return $id;
    }
}
