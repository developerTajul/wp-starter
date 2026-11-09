<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Application\DTOs\Responses\LeadOwnerOptionDTO;

/**
 * Read side of the port that fills the lead owner picker.
 *
 * The picker offers the users a lead can be handed to. Those rows belong to
 * the User module's table, so the read sits behind its own port rather than
 * widening the module's main query surface for one lookup.
 */
interface LeadOwnerFormOptionsContract
{
    /**
     * Returns the users the owner picker offers.
     *
     * @return array<int, LeadOwnerOptionDTO> Empty when no user can own a lead.
     */
    public function getOwnerOptions(): array;
}
