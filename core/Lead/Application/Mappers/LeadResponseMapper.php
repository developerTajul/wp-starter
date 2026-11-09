<?php

declare(strict_types=1);

namespace Core\Lead\Application\Mappers;

use Core\Lead\Application\DTOs\Responses\LeadResponseDTO;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Rules\LeadTransitions;
use Core\Lead\Domain\ReadModels\LeadProjectionDTO;
use Core\Shared\Domain\Contracts\TranslatorContract;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Maps a lead read model or entity to LeadResponseDTO.
 */
final class LeadResponseMapper
{
    /**
     * @param TranslatorContract $translator
     */
    public function __construct(
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * Builds the response DTO from the lead projection.
     *
     * @param LeadProjectionDTO $projection
     *
     * @return LeadResponseDTO
     */
    public function toResponseDTO(LeadProjectionDTO $projection): LeadResponseDTO
    {
        return $this->build(
            id: $projection->id,
            name: $projection->name,
            email: $projection->email,
            phone: $projection->phone,
            companyName: $projection->companyName,
            status: $projection->status,
            source: $projection->source,
            assignedTo: $projection->assignedTo,
            createdAt: $projection->createdAt?->format(DateTimeInterface::ATOM),
            updatedAt: $projection->updatedAt?->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * Builds the response DTO from a lead the write path already holds, so a
     * command result leaves in the same shape a query result does.
     *
     * @param LeadEntity $entity
     *
     * @return LeadResponseDTO
     */
    public function toResponseDTOFromEntity(LeadEntity $entity): LeadResponseDTO
    {
        return $this->build(
            id: $entity->id,
            name: $entity->name,
            email: $entity->email,
            phone: $entity->phone,
            companyName: $entity->companyName,
            status: $entity->getStatus(),
            source: $entity->source,
            assignedTo: $entity->getAssignedTo(),
            createdAt: $this->toAtom($entity->createdAt),
            updatedAt: $this->toAtom($entity->updatedAt),
        );
    }

    /**
     * Assembles the response from a lead and its already-formatted dates.
     *
     * @param int|null    $id
     * @param string      $name
     * @param string|null $email
     * @param string|null $phone
     * @param string|null $companyName
     * @param LeadStatus  $status
     * @param string      $source
     * @param int|null    $assignedTo
     * @param string|null $createdAt
     * @param string|null $updatedAt
     *
     * @return LeadResponseDTO
     */
    private function build(
        ?int $id,
        string $name,
        ?string $email,
        ?string $phone,
        ?string $companyName,
        LeadStatus $status,
        string $source,
        ?int $assignedTo,
        ?string $createdAt,
        ?string $updatedAt,
    ): LeadResponseDTO {
        return new LeadResponseDTO(
            id: $id,
            name: $name,
            email: $email,
            phone: $phone,
            company_name: $companyName,
            status: $status->value,
            status_label: $this->translator->translate($status->translationKey()),
            source: $source,
            // Every datetime leaves on the same ISO-8601 wire format. The list
            // view reformats it for display; a client parses it as-is.
            created_at: $createdAt,
            updated_at: $updatedAt,
            in_pipeline: $status->isOnPipeline(),
            pipeline_progress: $status->pipelineProgress(),
            pipeline_steps: $this->toPipelineSteps($status),
            status_options: $this->toStatusOptions($status),
            // Carried straight from the lead. The owner's *name* is not
            // resolved here: that would pull the user directory into this
            // mapper, and delivery already holds the option list it needs.
            assigned_to: $assignedTo,
        );
    }

    /**
     * Builds the progress bar's stages, marking how far the lead has walked.
     *
     * A lead that has left the pipeline reports no stages at all, so the bar is
     * never drawn as if the lead were still progressing.
     *
     * @param LeadStatus $status
     *
     * @return list<array{value: string, label: string, reached: bool, current: bool}>
     */
    private function toPipelineSteps(LeadStatus $status): array
    {
        if (! $status->isOnPipeline()) {
            return [];
        }

        $currentPosition = $status->pipelinePosition();

        return array_map(
            fn(LeadStatus $step) => [
                'value' => $step->value,
                'label' => $this->translator->translate($step->translationKey()),
                'reached' => $step->pipelinePosition() <= $currentPosition,
                'current' => $step === $status,
            ],
            LeadStatus::PIPELINE,
        );
    }

    /**
     * Builds the statuses this lead may move to next.
     *
     * The list comes from the transition rules, so a status the domain forbids
     * is never offered as a choice.
     *
     * @param LeadStatus $status
     *
     * @return list<array{value: string, label: string}>
     */
    private function toStatusOptions(LeadStatus $status): array
    {
        return array_map(
            fn(LeadStatus $option) => [
                'value' => $option->value,
                'label' => $this->translator->translate($option->translationKey()),
            ],
            LeadTransitions::reachableFrom($status),
        );
    }

    /**
     * Formats a stored datetime the way the read path does. Stored datetimes
     * are UTC wall-clock strings, so they are read back as UTC too.
     *
     * @param string $stored
     *
     * @return string|null
     */
    private function toAtom(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }

        return (new DateTimeImmutable($stored, new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }
}