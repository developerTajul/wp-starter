<?php

declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

/**
 * Port for password hashing and verification.
 */
interface PasswordHasherContract
{
    /**
     * Hashes a plain-text password.
     *
     * @param string $plainPassword
     *
     * @return string
     */
    public function hash(string $plainPassword): string;

    /**
     * Checks a plain-text password against a stored hash.
     *
     * @param string $plainPassword
     * @param string $hashedPassword
     *
     * @return bool
     */
    public function verify(string $plainPassword, string $hashedPassword): bool;
}
