<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Security;

use Core\Shared\Domain\Contracts\PasswordHasherContract;
use Illuminate\Support\Facades\Hash;

/**
 * Password hashing backed by Laravel's Hash facade.
 *
 * @implements \Core\Shared\Domain\Contracts\PasswordHasherContract
 */
final class LaravelPasswordHasher implements PasswordHasherContract
{
    public function hash(string $plainPassword): string
    {
        return Hash::make($plainPassword);
    }

    public function verify(string $plainPassword, string $hashedPassword): bool
    {
        return Hash::check($plainPassword, $hashedPassword);
    }
}
