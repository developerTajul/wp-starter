<?php

declare(strict_types=1);

namespace Core\Auth\Application\Contracts;

use Core\User\Domain\Entities\UserEntity;

/**
 * PORT: the read side of auth persistence.
 *
 * Answers the reads the auth use cases need without going through the write
 * port: credential lookup for a sign-in, and the profile read.
 */
interface AuthQueryContract
{
    /**
     * Finds an account by email address.
     *
     * @param string $email The account email.
     *
     * @return UserEntity|null Null when no account matches.
     */
    public function findByEmail(string $email): ?UserEntity;

    /**
     * Finds an account by id.
     *
     * @param int $id The account id.
     *
     * @return UserEntity|null Null when no account carries the id.
     */
    public function findById(int $id): ?UserEntity;
}
