<?php

declare(strict_types=1);

namespace Core\Auth\Domain\Contracts;

use Core\User\Domain\Entities\UserEntity;

/**
 * PORT: the write side of auth persistence.
 *
 * Takes and returns Entities only. Every read lives on AuthQueryContract: the
 * only use case here creates an account, so no row lock is needed yet.
 */
interface AuthRepositoryContract
{
    /**
     * Stores a new account and returns it with the id the table assigned.
     *
     * @param UserEntity $user The unsaved account.
     *
     * @return UserEntity The stored account, carrying its assigned id.
     *
     * @throws \Core\Auth\Domain\Exceptions\AuthDuplicateException When the email is already taken.
     */
    public function create(UserEntity $user): UserEntity;
}
