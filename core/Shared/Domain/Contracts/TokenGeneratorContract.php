<?php

declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

/**
 * Port for issuing an API access token for a user.
 */
interface TokenGeneratorContract
{
    /**
     * Issues an access token for the given user ID.
     *
     * @param int $userId
     *
     * @return string The plain-text token.
     */
    public function generate(int $userId): string;
}
