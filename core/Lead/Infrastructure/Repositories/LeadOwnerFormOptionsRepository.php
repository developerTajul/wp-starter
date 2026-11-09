<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

use Core\Lead\Application\Contracts\LeadOwnerFormOptionsContract;
use Core\Lead\Application\DTOs\Responses\LeadOwnerOptionDTO;
use Core\User\Application\Contracts\UserQueryContract;
use Core\User\Domain\ValueObjects\UserFilter;

/**
 * Fills the lead owner picker from the user list read.
 *
 * Reads through the User module's own read port rather than the users table:
 * the picker wants the same credential-free rows the user list shows, and the
 * port already guarantees the password hash is never selected. Only the two
 * fields an option needs are carried on (see LeadOwnerOptionDTO).
 *
 * @implements LeadOwnerFormOptionsContract
 */
final class LeadOwnerFormOptionsRepository implements LeadOwnerFormOptionsContract
{
    /**
     * How many users the picker offers at once. The owner picker is a plain
     * select, so it stays a single bounded read rather than a paged one; a
     * directory larger than this needs a searchable picker, not a longer list.
     */
    private const OPTION_LIMIT = 100;

    /**
     * @param UserQueryContract $userQuery
     */
    public function __construct(
        private readonly UserQueryContract $userQuery,
    ) {}

    /**
     * Returns the users the owner picker offers.
     *
     * @return array<int, LeadOwnerOptionDTO>
     */
    public function getOwnerOptions(): array
    {
        $users = $this->userQuery->getFilteredUsers(new UserFilter(perPage: self::OPTION_LIMIT));

        $options = [];

        foreach ($users->items as $user) {
            $options[] = new LeadOwnerOptionDTO(
                id: $user->id,
                name: $user->name,
            );
        }

        return $options;
    }
}
