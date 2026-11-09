<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\Mappers;

use Core\Pipeline\Application\Contracts\PipelineMapperContract;
use Core\Pipeline\Application\DTOs\Requests\StorePipelineDTO;
use Core\Pipeline\Application\DTOs\Requests\StorePipelineStageDTO;
use Core\Pipeline\Application\DTOs\Requests\UpdatePipelineDTO;
use InvalidArgumentException;

/**
 * Maps validated request input to Pipeline DTOs.
 *
 * @implements PipelineMapperContract
 */
final class PipelineMapper implements PipelineMapperContract
{
    /**
     * Builds a StorePipelineDTO from validated input.
     *
     * The stage list is rebuilt row by row, so the DTO hands the Service a list
     * of stage objects rather than the raw nested arrays the request carried.
     *
     * @param array $validatedData
     *
     * @return StorePipelineDTO
     *
     * @throws InvalidArgumentException When the stage list is not a usable list.
     */
    public function mapToStoreDTO(array $validatedData): StorePipelineDTO
    {
        return new StorePipelineDTO(
            name: trim((string) $validatedData['name']),
            isDefault: $this->toBoolean($validatedData['is_default'] ?? false),
            stages: $this->toStageDTOs($validatedData['stages'] ?? null),
        );
    }

    /**
     * Builds an UpdatePipelineDTO from validated input.
     *
     * An edit carries the whole pipeline, so the stage list replaces the stored
     * one outright rather than merging into it.
     *
     * @param int   $id            The pipeline ID from the route.
     * @param array $validatedData The validated input data from the delivery layer.
     *
     * @return UpdatePipelineDTO
     *
     * @throws InvalidArgumentException When the stage list is not a usable list.
     */
    public function mapToUpdateDTO(int $id, array $validatedData): UpdatePipelineDTO
    {
        return new UpdatePipelineDTO(
            id: $id,
            name: trim((string) $validatedData['name']),
            isDefault: $this->toBoolean($validatedData['is_default'] ?? false),
            stages: $this->toStageDTOs($validatedData['stages'] ?? null),
        );
    }

    /**
     * Builds one DTO per stage row.
     *
     * @param mixed $stages
     *
     * @return array<int, StorePipelineStageDTO>
     *
     * @throws InvalidArgumentException When the value is not a list of stage rows.
     */
    private function toStageDTOs(mixed $stages): array
    {
        if (! is_array($stages)) {
            throw new InvalidArgumentException('Unsupported value for field [stages]. A list of stages is required.');
        }

        return array_map($this->toStageDTO(...), array_values($stages));
    }

    /**
     * Builds the DTO for one stage row.
     *
     * @param mixed $stage
     *
     * @return StorePipelineStageDTO
     *
     * @throws InvalidArgumentException When the row is not a stage.
     */
    private function toStageDTO(mixed $stage): StorePipelineStageDTO
    {
        if (! is_array($stage)) {
            throw new InvalidArgumentException('Unsupported value for field [stages]. Each stage must be an object.');
        }

        return new StorePipelineStageDTO(
            name: trim((string) ($stage['name'] ?? '')),
            orderPosition: $this->toInteger($stage['order_position'] ?? null, 'stages.*.order_position'),
            probability: $this->toInteger($stage['probability'] ?? null, 'stages.*.probability'),
        );
    }

    /**
     * Converts a request value to a real boolean. A cast would read the
     * string "false" as true.
     *
     * @param mixed $value
     *
     * @return bool
     */
    private function toBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Narrows a supplied value to an integer. The bounds each field allows are
     * the value object's to enforce, so this only turns the input into the type
     * the DTO declares.
     *
     * @param mixed  $value
     * @param string $field
     *
     * @return int
     *
     * @throws InvalidArgumentException When the value is not a whole number.
     */
    private function toInteger(mixed $value, string $field): int
    {
        if (! is_numeric($value) || (int) $value != $value) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for field [%s]. A whole number is required.',
                is_scalar($value) ? (string) $value : get_debug_type($value),
                $field,
            ));
        }

        return (int) $value;
    }
}
