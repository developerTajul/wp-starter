<?php

declare(strict_types=1);

namespace Core\Contact\Application\Contracts;

use Core\Contact\Application\DTOs\Requests\ContactFilterDTO;
use Core\Contact\Application\DTOs\Requests\StoreContactDTO;
use Core\Contact\Application\DTOs\Requests\UpdateContactDTO;

/**
 * PORT: maps validated delivery-layer input to contact DTOs.
 */
interface ContactMapperContract
{
    /**
     * Builds a StoreContactDTO from validated input.
     *
     * @param array $validatedData
     *
     * @return StoreContactDTO
     */
    public function mapToStoreDTO(array $validatedData): StoreContactDTO;

    /**
     * Builds an UpdateContactDTO from validated input.
     *
     * @param int   $id            The contact ID from the route.
     * @param array $validatedData The validated input data from the delivery layer.
     *
     * @return UpdateContactDTO
     */
    public function mapToUpdateDTO(int $contactId, array $validatedData): UpdateContactDTO;

    /**
     * Builds a ContactFilterDTO from query input.
     *
     * @param array $queryParams
     *
     * @return ContactFilterDTO
     */
    public function mapToFilterDTO(array $queryParams): ContactFilterDTO;
}
