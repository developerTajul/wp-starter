<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\Mappers;

use Core\Pipeline\Application\DTOs\Responses\KanbanBoardResponseDTO;
use Core\Pipeline\Application\DTOs\Responses\KanbanDealResponseDTO;
use Core\Pipeline\Application\DTOs\Responses\KanbanStageResponseDTO;
use Core\Pipeline\Domain\DataObjects\KanbanBoardData;
use Core\Pipeline\Domain\DataObjects\KanbanDealData;
use Core\Pipeline\Domain\DataObjects\KanbanStageData;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Maps a Kanban board read model to KanbanBoardResponseDTO.
 */
final class KanbanBoardResponseMapper
{
    /**
     * Builds the board response DTO: the pipeline, and one column per stage in
     * the order they are worked through. Each column is told to total its own
     * deals rather than having the figures read back off it.
     *
     * @param KanbanBoardData $kanbanBoard
     *
     * @return KanbanBoardResponseDTO
     */
    public function toResponseDTO(KanbanBoardData $kanbanBoard): KanbanBoardResponseDTO
    {
        $pipeline = $kanbanBoard->pipeline;

        return new KanbanBoardResponseDTO(
            id: $pipeline->id,
            name: $pipeline->name,
            is_default: $pipeline->isDefault,
            stages: array_map($this->toStageDTO(...), $kanbanBoard->stages),
        );
    }

    /**
     * @param KanbanStageData $stage
     *
     * @return KanbanStageResponseDTO
     */
    private function toStageDTO(KanbanStageData $stage): KanbanStageResponseDTO
    {
        return new KanbanStageResponseDTO(
            id: $stage->id,
            name: $stage->name,
            order_position: $stage->orderPosition,
            probability: $stage->probability,
            deals_count: $stage->dealsCount(),
            total_value: $stage->totalValue(),
            deals: array_map($this->toDealDTO(...), $stage->deals),
        );
    }

    /**
     * @param KanbanDealData $deal
     *
     * @return KanbanDealResponseDTO
     */
    private function toDealDTO(KanbanDealData $deal): KanbanDealResponseDTO
    {
        return new KanbanDealResponseDTO(
            id: $deal->id,
            title: $deal->title,
            company_id: $deal->companyId,
            contact_id: $deal->contactId,
            amount: $deal->amount,
            currency: $deal->currency,
            pipeline_id: $deal->pipelineId,
            deal_stage_id: $deal->dealStageId,
            expected_close_date: $deal->expectedCloseDate,
            actual_close_date: $deal->actualCloseDate,
            created_at: $this->toAtom($deal->createdAt),
            updated_at: $this->toAtom($deal->updatedAt),
            // Only the columns the board prints are carried, so the wire stays
            // two fields rather than the whole related record.
            company: $deal->companyName === null ? null : [
                'id' => $deal->companyId,
                'name' => $deal->companyName,
            ],
            contact: $deal->contactName === null ? null : [
                'id' => $deal->contactId,
                'name' => $deal->contactName,
            ],
        );
    }

    /**
     * Formats a stored UTC timestamp as ISO-8601. Parsing it in the application
     * timezone would shift the instant on every round trip.
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

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }
}
