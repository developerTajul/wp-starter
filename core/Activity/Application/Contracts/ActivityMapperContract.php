<?php

declare(strict_types=1);

namespace Core\Activity\Application\Contracts;

use Core\Activity\Application\DTOs\Requests\ActivityCreateDTO;
use Core\Activity\Application\DTOs\Requests\ActivityFilterDTO;
use Core\Activity\Application\DTOs\Requests\ActivityUpdateDTO;

/**
 * Defines how validated input maps to Activity DTOs.
 */
interface ActivityMapperContract
{
    /**
     * Builds an ActivityCreateDTO from validated input.
     *
     * @param array $validatedData The validated input data from the delivery layer.
     *
     * @return ActivityCreateDTO
     *
     * @throws \InvalidArgumentException When a value cannot be converted.
     */
    public function mapToCreateDTO(array $validatedData): ActivityCreateDTO;

    /**
     * Builds an ActivityUpdateDTO from validated input.
     *
     * @param int   $id            The activity ID from the route.
     * @param array $validatedData The validated input data from the delivery layer.
     *
     * @return ActivityUpdateDTO
     *
     * @throws \InvalidArgumentException When a value cannot be converted.
     */
    public function mapToUpdateDTO(int $id, array $validatedData): ActivityUpdateDTO;

    /**
     * Builds an ActivityFilterDTO from query input, rejecting unrecognised values.
     *
     * @param array $queryParams The raw query string values.
     *
     * @return ActivityFilterDTO
     *
     * @throws \InvalidArgumentException When a supplied value is not recognised.
     */
    public function mapToFilterDTO(array $queryParams): ActivityFilterDTO;

    /**
     * Builds an ActivityFilterDTO for user-facing screens, where a stale
     * bookmark must not break the page. Values that cannot be converted are
     * dropped, and the dropped field names come back so the caller can tell
     * the user what it ignored.
     *
     * @param array $queryParams The raw query string values.
     *
     * @return array{0: ActivityFilterDTO, 1: array<int, string>} The DTO and the ignored fields.
     */
    public function mapToFilterDTOLenient(array $queryParams): array;

    /**
     * Builds the options for the attach-to select, from the record types an
     * activity may be attached to. Each option pairs the stored morph alias
     * with the label shown for it.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toTrackableTypeOptions(): array;
}