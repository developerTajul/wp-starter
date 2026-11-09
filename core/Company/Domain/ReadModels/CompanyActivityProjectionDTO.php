<?php

declare(strict_types=1);

namespace Core\Company\Domain\ReadModels;

/**
 * An activity row as the company detail read returns it, with the name of the
 * user who logged it already resolved.
 */
final readonly class CompanyActivityProjectionDTO
{
    /**
     * @param int         $id
     * @param string      $type
     * @param string      $subject
     * @param string|null $description
     * @param string|null $dueDate
     * @param string|null $completedAt
     * @param string|null $userName
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $subject,
        public ?string $description,
        public ?string $dueDate,
        public ?string $completedAt,
        public ?string $userName,
    ) {}
}
