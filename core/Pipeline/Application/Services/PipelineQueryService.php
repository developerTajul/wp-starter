<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\Services;

use Core\Pipeline\Application\Contracts\PipelineQueryContract;
use Core\Pipeline\Application\Contracts\PipelineQueryServiceContract;
use Core\Pipeline\Application\Mappers\KanbanBoardResponseMapper;
use Core\Pipeline\Application\Mappers\PipelineResponseMapper;
use Core\Pipeline\Domain\DataObjects\PipelineDetailData;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;

/**
 * Handles pipeline read use cases. Never changes state.
 *
 * @implements PipelineQueryServiceContract
 */
final class PipelineQueryService implements PipelineQueryServiceContract
{
    private const ERROR_INDEX_FAILED = 'PIPELINE_INDEX_FAILED';

    private const ERROR_NOT_FOUND = 'PIPELINE_NOT_FOUND';

    private const ERROR_DETAIL_FAILED = 'PIPELINE_DETAIL_FAILED';

    /**
     * @param PipelineQueryContract     $pipelineQuery
     * @param PipelineResponseMapper    $responseMapper
     * @param KanbanBoardResponseMapper $kanbanResponseMapper
     * @param TranslatorContract        $translator
     * @param LoggerInterface           $logger
     */
    public function __construct(
        private readonly PipelineQueryContract $pipelineQuery,
        private readonly PipelineResponseMapper $responseMapper,
        private readonly KanbanBoardResponseMapper $kanbanResponseMapper,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns every pipeline with the stages it is made of.
     *
     * @return Result Contains the list of PipelineResponseDTO on success.
     */
    public function getPipelines(): Result
    {
        try {
            $pipelines = $this->pipelineQuery->getAllWithStages();

            // The read models become response DTOs here, so nothing downstream
            // of the Service ever sees a domain object.
            $pipelineResponseDTOs = array_map(
                fn(PipelineDetailData $pipelineDetail) => $this->responseMapper->toResponseDTOFromProjection(
                    pipeline: $pipelineDetail->pipeline,
                    stages: $pipelineDetail->stages,
                ),
                $pipelines,
            );

            return Result::success(
                data: $pipelineResponseDTOs,
                message: $this->translator->translate(key: 'pipelines.retrieved'),
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: 'Failed to retrieve pipelines.',
                context: ['exception' => $exception],
            );

            return Result::failure(
                message: $this->translator->translate(key: 'pipelines.errors.index_failed'),
                errorCode: self::ERROR_INDEX_FAILED,
            );
        }
    }

    /**
     * Returns one pipeline together with the stages it is made of.
     *
     * @param string $id
     *
     * @return Result Contains the PipelineDetailResponseDTO on success.
     */
    public function getPipelineDetails(string $id): Result
    {
        try {
            $pipelineDetail = $this->pipelineQuery->findDetailById((int) $id);

            // A missing pipeline is an ordinary outcome of a read, so it is
            // reported rather than thrown.
            if ($pipelineDetail === null) {
                return Result::failure(
                    message: $this->translator->translate(key: 'pipelines.errors.pipeline_not_found'),
                    errorCode: self::ERROR_NOT_FOUND,
                );
            }

            return Result::success(
                data: $this->responseMapper->toDetailResponseDTO($pipelineDetail),
                message: $this->translator->translate(key: 'pipelines.retrieved_single'),
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: 'Failed to retrieve pipeline.',
                context: ['exception' => $exception],
            );

            return Result::failure(
                message: $this->translator->translate(key: 'pipelines.errors.detail_failed'),
                errorCode: self::ERROR_DETAIL_FAILED,
            );
        }
    }

    /**
     * Returns the Kanban board for one pipeline: its stages, and the deals
     * sitting in each.
     *
     * @param string $id
     *
     * @return Result Contains the KanbanBoardResponseDTO on success.
     */
    public function getKanbanBoard(string $id): Result
    {
        try {
            $kanbanBoard = $this->pipelineQuery->findKanbanBoardById((int) $id);

            // A missing pipeline is an ordinary outcome of a read, so it is
            // reported rather than thrown.
            if ($kanbanBoard === null) {
                return Result::failure(
                    message: $this->translator->translate(key: 'pipelines.errors.pipeline_not_found'),
                    errorCode: self::ERROR_NOT_FOUND,
                );
            }

            return Result::success(
                data: $this->kanbanResponseMapper->toResponseDTO($kanbanBoard),
                message: $this->translator->translate(key: 'pipelines.kanban_retrieved'),
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: 'Failed to retrieve the pipeline Kanban board.',
                context: ['exception' => $exception],
            );

            return Result::failure(
                message: $this->translator->translate(key: 'pipelines.errors.kanban_failed'),
                errorCode: self::ERROR_DETAIL_FAILED,
            );
        }
    }
}
