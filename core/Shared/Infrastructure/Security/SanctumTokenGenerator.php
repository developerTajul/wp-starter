<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Security;

use App\Models\User;
use Core\Shared\Domain\Contracts\TokenGeneratorContract;

/**
 * Issues Sanctum personal access tokens for users.
 *
 * @implements \Core\Shared\Domain\Contracts\TokenGeneratorContract
 */
final class SanctumTokenGenerator implements TokenGeneratorContract
{
    private const TOKEN_NAME = 'auth';

    public function __construct(
        private readonly User $model,
    ) {}

    public function generate(int $userId): string
    {
        $user = $this->model->newQuery()->find($userId);

        if ($user === null) {
            throw new \RuntimeException("Cannot issue token: user [{$userId}] not found.");
        }

        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
