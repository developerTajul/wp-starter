<?php

declare(strict_types=1);

namespace Core\Auth\Infrastructure\Repositories;

use App\Models\User as UserModel;
use Core\Auth\Application\Contracts\AuthQueryContract;
use Core\User\Domain\Entities\UserEntity;
use Core\Auth\Infrastructure\Mappers\AuthEntityMapper;

/**
 * Eloquent-backed reads for auth accounts.
 *
 * Extends nothing: it holds no write helper, so this adapter cannot save an
 * account. Both reads have to carry the password hash, because a sign-in
 * verifies against it and the profile response is built from the same entity.
 *
 * @implements AuthQueryContract
 */
final class AuthReadRepository implements AuthQueryContract
{
    /**
     * @param AuthEntityMapper $mapper
     */
    public function __construct(
        private readonly AuthEntityMapper $mapper,
    ) {}

    /**
     * Finds an account by email address.
     *
     * @param string $email The account email.
     *
     * @return UserEntity|null Null when no account matches.
     */
    public function findByEmail(string $email): ?UserEntity
    {
        $userModel = UserModel::query()->where('email', $email)->first();

        if (! $userModel instanceof UserModel) {
            return null;
        }

        return $this->mapper->toEntity($userModel->getAttributes());
    }

    /**
     * Finds an account by id.
     *
     * @param int $id The account id.
     *
     * @return UserEntity|null Null when no account carries the id.
     */
    public function findById(int $id): ?UserEntity
    {
        $userModel = UserModel::query()->find($id);

        if (! $userModel instanceof UserModel) {
            return null;
        }

        return $this->mapper->toEntity($userModel->getAttributes());
    }
}
