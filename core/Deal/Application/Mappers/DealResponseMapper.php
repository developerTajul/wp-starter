<?php

declare(strict_types=1);

namespace Core\Deal\Application\Mappers;

use Core\Deal\Application\DTOs\Responses\DealActivityResponseDTO;
use Core\Deal\Application\DTOs\Responses\DealCompanyResponseDTO;
use Core\Deal\Application\DTOs\Responses\DealContactResponseDTO;
use Core\Deal\Application\DTOs\Responses\DealDetailResponseDTO;
use Core\Deal\Application\DTOs\Responses\DealFormOptionsResponseDTO;
use Core\Deal\Application\DTOs\Responses\DealOptionResponseDTO;
use Core\Deal\Application\DTOs\Responses\DealPipelineResponseDTO;
use Core\Deal\Application\DTOs\Responses\DealResponseDTO;
use Core\Deal\Application\DTOs\Responses\DealStageResponseDTO;
use Core\Deal\Domain\DataObjects\DealFormOptions;
use Core\Deal\Domain\Entities\DealEntity;
use Core\Deal\Domain\ReadModels\DealProjectionDTO;
use Core\Deal\Domain\ReadModels\StoredDealData;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Maps a deal entity to DealResponseDTO.
 *
 * The detail read carries a stored graph rather than a bare entity, so a raw
 * timestamp can arrive as a Carbon instance instead of the string a
 * reconstituted entity holds. toAtom() accepts both.
 */
final class DealResponseMapper
{
    /**
     * Builds the response DTO from a deal. Timestamps are stored as UTC
     * wall-clock strings and leave on the same ISO-8601 wire format the other
     * modules use, so a client parses every module's dates alike.
     *
     * @param DealEntity $deal
     *
     * @return DealResponseDTO
     */
    public function toResponseDTO(DealEntity $deal): DealResponseDTO
    {
        return new DealResponseDTO(
            id: $deal->getId(),
            title: $deal->getTitle(),
            company_id: $deal->getCompanyId(),
            contact_id: $deal->getContactId(),
            amount: $deal->getAmount(),
            currency: $deal->getCurrency(),
            pipeline_id: $deal->getPipelineId(),
            deal_stage_id: $deal->getDealStageId(),
            expected_close_date: $deal->getExpectedCloseDate(),
            actual_close_date: $deal->getActualCloseDate(),
            created_at: $this->toAtom($deal->getCreatedAt()),
            updated_at: $this->toAtom($deal->getUpdatedAt()),
        );
    }

    /**
     * Builds the same response DTO from a list read model. The list never
     * hydrates an entity, so the values are taken straight from the projection.
     *
     * @param DealProjectionDTO $projection
     *
     * @return DealResponseDTO
     */
    public function toResponseDTOFromProjection(DealProjectionDTO $projection): DealResponseDTO
    {
        return new DealResponseDTO(
            id: $projection->id,
            title: $projection->title,
            company_id: $projection->companyId,
            contact_id: $projection->contactId,
            amount: $projection->amount,
            currency: $projection->currency,
            pipeline_id: $projection->pipelineId,
            deal_stage_id: $projection->dealStageId,
            expected_close_date: $projection->expectedCloseDate,
            actual_close_date: $projection->actualCloseDate,
            created_at: $this->toAtom($projection->createdAt),
            updated_at: $this->toAtom($projection->updatedAt),
        );
    }

    /**
     * Builds the detail DTO: the deal's own fields plus the records it points
     * at and the activities logged against it. This is the one place the
     * flattened rows the repository returns become nested DTOs.
     *
     * @param StoredDealData $storedDeal
     *
     * @return DealDetailResponseDTO
     */
    public function toDetailResponseDTO(StoredDealData $storedDeal): DealDetailResponseDTO
    {
        $deal = $storedDeal->deal;

        return new DealDetailResponseDTO(
            id: $deal->getId(),
            title: $deal->getTitle(),
            company_id: $deal->getCompanyId(),
            contact_id: $deal->getContactId(),
            amount: $deal->getAmount(),
            currency: $deal->getCurrency(),
            pipeline_id: $deal->getPipelineId(),
            deal_stage_id: $deal->getDealStageId(),
            expected_close_date: $deal->getExpectedCloseDate(),
            actual_close_date: $deal->getActualCloseDate(),
            created_at: $this->toAtom($deal->getCreatedAt()),
            updated_at: $this->toAtom($deal->getUpdatedAt()),
            company: $this->toCompanyDTO($storedDeal->company),
            contact: $this->toContactDTO($storedDeal->contact),
            pipeline: $this->toPipelineDTO($storedDeal->pipeline),
            stage: $this->toStageDTO($storedDeal->stage),
            activities: array_map($this->toActivityDTO(...), $storedDeal->activities),
        );
    }

    /**
     * @param array<string, mixed>|null $company
     *
     * @return DealCompanyResponseDTO|null
     */
    private function toCompanyDTO(?array $company): ?DealCompanyResponseDTO
    {
        if ($company === null) {
            return null;
        }

        return new DealCompanyResponseDTO(
            id: (int) $company['id'],
            name: (string) $company['name'],
        );
    }

    /**
     * @param array<string, mixed>|null $contact
     *
     * @return DealContactResponseDTO|null
     */
    private function toContactDTO(?array $contact): ?DealContactResponseDTO
    {
        if ($contact === null) {
            return null;
        }

        return new DealContactResponseDTO(
            id: (int) $contact['id'],
            name: (string) $contact['name'],
        );
    }

    /**
     * @param array<string, mixed>|null $pipeline
     *
     * @return DealPipelineResponseDTO|null
     */
    private function toPipelineDTO(?array $pipeline): ?DealPipelineResponseDTO
    {
        if ($pipeline === null) {
            return null;
        }

        return new DealPipelineResponseDTO(
            id: (int) $pipeline['id'],
            name: (string) $pipeline['name'],
            is_default: (bool) ($pipeline['is_default'] ?? false),
        );
    }

    /**
     * @param array<string, mixed>|null $stage
     *
     * @return DealStageResponseDTO|null
     */
    private function toStageDTO(?array $stage): ?DealStageResponseDTO
    {
        if ($stage === null) {
            return null;
        }

        return new DealStageResponseDTO(
            id: (int) $stage['id'],
            name: (string) $stage['name'],
            probability: (int) ($stage['probability'] ?? 0),
            order_position: (int) ($stage['order_position'] ?? 1),
        );
    }

    /**
     * @param array<string, mixed> $activity
     *
     * @return DealActivityResponseDTO
     */
    private function toActivityDTO(array $activity): DealActivityResponseDTO
    {
        return new DealActivityResponseDTO(
            id: (int) $activity['id'],
            type: (string) $activity['type'],
            subject: (string) $activity['subject'],
            description: $activity['description'] ?? null,
            due_date: $activity['due_date'] ?? null,
            completed_at: $activity['completed_at'] ?? null,
            user_name: $activity['user_name'] ?? null,
        );
    }

    /**
     * Builds the deal form's picker rows. Each picker carries only the id a form
     * submits and the label it shows; the stages also carry the pipeline they
     * belong to, so the form can narrow the stage list to the chosen pipeline.
     *
     * @param DealFormOptions $formOptions
     *
     * @return DealFormOptionsResponseDTO
     */
    public function toFormOptionsResponseDTO(DealFormOptions $formOptions): DealFormOptionsResponseDTO
    {
        return new DealFormOptionsResponseDTO(
            pipelines: array_map(
                fn(array $row) => $this->toOptionDTO($row, 'name'),
                $formOptions->pipelines,
            ),
            stages: array_map($this->toStageOptionDTO(...), $formOptions->stages),
            companies: array_map(
                fn(array $row) => $this->toOptionDTO($row, 'name'),
                $formOptions->companies,
            ),
            contacts: array_map(
                fn(array $row) => $this->toOptionDTO($row, 'name'),
                $formOptions->contacts,
            ),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return DealOptionResponseDTO
     */
    private function toStageOptionDTO(array $row): DealOptionResponseDTO
    {
        return new DealOptionResponseDTO(
            id: (int) $row['id'],
            label: (string) $row['name'],
            group_id: (int) $row['pipeline_id'],
            group_name: (string) ($row['pipeline_name'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param string               $labelColumn
     *
     * @return DealOptionResponseDTO
     */
    private function toOptionDTO(array $row, string $labelColumn): DealOptionResponseDTO
    {
        return new DealOptionResponseDTO(
            id: (int) $row['id'],
            label: (string) $row[$labelColumn],
        );
    }

    /**
     * Formats a stored UTC timestamp as ISO-8601.
     *
     * @param string|null $value
     *
     * @return string|null
     */
    private function toAtom(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable(datetime: $value, timezone: new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }
}
