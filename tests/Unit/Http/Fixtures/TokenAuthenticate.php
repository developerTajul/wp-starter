<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Framework\Http\Middleware\Authenticate;
use Framework\Http\Request;

/**
 * The documented headless seam: a real (if trivial) token scheme.
 *
 * It exists to pin down {@see Authenticate::resolveUser()}'s contract rather
 * than to be a token implementation — the framework deliberately ships none, and
 * the point of the test is that a project can supply one without subclassing
 * anything about the cookie path.
 *
 * A token that matches yields a fixed user id; anything else yields 0, which the
 * base class reads as "not authenticated by this scheme" and refuses outright.
 */
final class TokenAuthenticate extends Authenticate
{
    public const USER_ID = 42;

    public function __construct(private readonly string $expected)
    {
        // A token client is by definition asking for a machine-readable answer,
        // so the login-form redirect is off.
        parent::__construct(redirectToLogin: false);
    }

    protected function resolveUser(Request $request): int
    {
        return $request->header('authorization') === 'Bearer ' . $this->expected
            ? self::USER_ID
            : 0;
    }
}
