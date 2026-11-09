<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Responses;

/**
 * Shape the lead list is handed to the delivery layer in.
 */
final readonly class LeadResponseDTO
{
    /**
     * @param list<array{value: string, label: string, reached: bool, current: bool}> $pipeline_steps
     *        The progress bar's stages, already resolved to labels. Empty when
     *        the lead has left the pipeline.
     * @param list<array{value: string, label: string}> $status_options
     *        The statuses this lead may move to next. Empty when it is final.
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $company_name,
        public string $status,
        public string $status_label,
        public string $source,
        public ?string $created_at = null,
        public ?string $updated_at = null,
        public bool $in_pipeline = true,
        public int $pipeline_progress = 0,
        public array $pipeline_steps = [],
        public array $status_options = [],
        public ?int $assigned_to = null,
    ) {}
}
