<?php

declare(strict_types=1);

namespace Core\Auth\Infrastructure\Repositories;

use App\Models\User as UserModel;
use Core\Auth\Domain\Contracts\AuthRepositoryContract;
use Core\Auth\Domain\Exceptions\AuthDuplicateException;
use Core\User\Domain\Entities\UserEntity;
use Core\Auth\Infrastructure\Mappers\AuthEntityMapper;
use Core\Shared\Infrastructure\Repositories\BaseRepository;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Eloquent-backed writes for auth accounts.
 *
 * @implements AuthRepositoryContract
 */
final class AuthWriteRepository extends BaseRepository implements AuthRepositoryContract
{
    /**
     * SQLSTATE for an integrity constraint violation - the unique email index
     * rejecting a duplicate.
     */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /**
     * @param UserModel        $model
     * @param AuthEntityMapper $mapper
     */
    public function __construct(
        UserModel $model,
        private readonly AuthEntityMapper $mapper,
    ) {
        parent::__construct($model);
    }

    /**
     * Stores a new account and returns it with the id the table assigned.
     *
     * @param UserEntity $user
     *
     * @return UserEntity
     *
     * @throws RuntimeException       When the account row could not be written.
     * @throws AuthDuplicateException When the unique email index rejects the row.
     */
    public function create(UserEntity $user): UserEntity
    {
        try {
            $userModel = $this->createRecord($this->mapper->toPersistenceArray($user));
        } catch (QueryException $exception) {
            throw $this->translateWriteFailure($exception);
        }

        if (! $userModel instanceof UserModel) {
            throw new RuntimeException('The account row could not be written.');
        }

        return $this->mapper->toEntity($this->rawColumns($userModel));
    }

    /**
     * Turns a failed write into the exception the Core is allowed to see.
     *
     * A unique-index violation means the account already exists and becomes the
     * module's own conflict exception. Any other database error is a real write
     * failure, so it keeps its original type rather than being disguised as a
     * duplicate - a caller must not treat an outage as "already exists".
     *
     * @param QueryException $exception
     *
     * @return \Throwable
     */
    private function translateWriteFailure(QueryException $exception): \Throwable
    {
        if ((string) $exception->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION) {
            return new AuthDuplicateException();
        }

        return $exception;
    }

    /**
     * Returns a model's raw column values for the EntityMapper.
     *
     * getAttributes() is used rather than toArray(): toArray() applies the
     * model's $hidden list and its casts, which would drop the password column
     * and rewrite the timestamps.
     *
     * @param UserModel $userModel
     *
     * @return array<string, mixed>
     */
    private function rawColumns(UserModel $userModel): array
    {
        return $userModel->getAttributes();
    }
}
