<?php

declare(strict_types=1);

namespace Core\Deal\Application\DTOs\Requests;

/**
 * Carries validated delivery-layer input INTO the Service for an update.
 * Immutable.
 *
 * An update has two states per field: absent (leave the stored value alone) or
 * a value (replace it). The required fields - title, amount, currency, pipeline
 * and stage - cannot be blanked, so a cleared one is an absent one. The two
 * links are optional on a deal, so they carry an explicit unlink flag: a
 * cleared picker on the form means "drop this link", which no null can express
 * because null already means "leave it as it is".
 *
 * The close date is not a field a client sends. It is decided by the stage the
 * deal ends up in, so the DTO carries the stage and its name together.
 */
final readonly class UpdateDealDTO
{
    /**
     * @param int         $id
     * @param string|null $title
     * @param int|null    $companyId
     * @param bool        $unlinkCompany
     * @param int|null    $contactId
     * @param bool        $unlinkContact
     * @param string|null $amount
     * @param string|null $currency
     * @param int|null    $pipelineId
     * @param int|null    $dealStageId
     * @param string|null $stageName The name of the stage being moved to.
     * @param string|null $expectedCloseDate
     */
    public function __construct(
        public int $id,
        public ?string $title = null,
        public ?int $companyId = null,
        public bool $unlinkCompany = false,
        public ?int $contactId = null,
        public bool $unlinkContact = false,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?int $pipelineId = null,
        public ?int $dealStageId = null,
        public ?string $stageName = null,
        public ?string $expectedCloseDate = null,
    ) {}
}
