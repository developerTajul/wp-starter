<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\Services;

use Core\Pipeline\Application\Contracts\PipelineCommandServiceContract;
use Core\Pipeline\Application\DTOs\Requests\StorePipelineDTO;
use Core\Pipeline\Application\DTOs\Requests\StorePipelineStageDTO;
use Core\Pipeline\Application\DTOs\Requests\UpdatePipelineDTO;
use Core\Pipeline\Application\Mappers\PipelineResponseMapper;
use Core\Pipeline\Domain\Contracts\PipelineRepositoryContract;
use Core\Pipeline\Domain\Entities\PipelineEntity;
use Core\Pipeline\Domain\Exceptions\InvalidPipelineException;
use Core\Pipeline\Domain\Exceptions\PipelineDuplicateException;
use Core\Pipeline\Domain\ValueObjects\PipelineStage;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;

/**
 * Handles pipeline write use cases. Business rules live in PipelineEntity.
 *
 * @implements PipelineCommandServiceContract
 */
final class PipelineCommandService implements PipelineCommandServiceContract
{
    private const ERROR_INVALID = 'PIPELINE_INVALID';

    private const ERROR_CREATE_FAILED = 'PIPELINE_CREATE_FAILED';

    private const ERROR_NOT_FOUND = 'PIPELINE_NOT_FOUND';

    private const ERROR_UPDATE_FAILED = 'PIPELINE_UPDATE_FAILED';

    private const ERROR_STAGE_CONFLICT = 'PIPELINE_STAGE_CONFLICT';

    /**
     * @param PipelineRepositoryContract $pipelineRepository
     * @param PipelineResponseMapper     $responseMapper
     * @param TransactionManagerContract $transactionManager
     * @param TranslatorContract         $translator
     * @param LoggerInterface            $logger
     */
    public function __construct(
        private readonly PipelineRepositoryContract $pipelineRepository,
        private readonly PipelineResponseMapper $responseMapper,
        private readonly TransactionManagerContract $transactionManager,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Creates a pipeline together with the stages it is made of.
     *
     * The entity guards the pipeline's own invariants and each stage value
     * object guards its own values; the DTOs only carry input.
     *
     * @param StorePipelineDTO $storePipelineDTO
     *
     * @return Result Contains the PipelineResponseDTO on success.
     */
    public function createPipeline(StorePipelineDTO $storePipelineDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($storePipelineDTO): Result {
                $pipelineEntity = PipelineEntity::create(
                    name: $storePipelineDTO->name,
                    isDefault: $storePipelineDTO->isDefault,
                    stages: array_map($this->toStage(...), $storePipelineDTO->stages),
                );

                $savedPipeline = $this->pipelineRepository->create($pipelineEntity);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($savedPipeline),
                    message: $this->translator->translate(key: 'pipelines.created'),
                );
            },
            logMessage: 'Pipeline creation failed.',
            fallbackMessageKey: 'pipelines.errors.create_failed',
            fallbackErrorCode: self::ERROR_CREATE_FAILED,
        );
    }

    /**
     * Applies an edit to an existing pipeline and its stages.
     *
     * The pipeline is read under a write lock so the state the edit is applied
     * to is the state being replaced, and the whole edit is one transaction: a
     * rejected stage set leaves the stored pipeline as it was.
     *
     * @param UpdatePipelineDTO $updatePipelineDTO
     *
     * @return Result Contains the PipelineResponseDTO on success.
     */
    public function update(UpdatePipelineDTO $updatePipelineDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($updatePipelineDTO): Result {
                $pipelineEntity = $this->pipelineRepository->findByIdForUpdate($updatePipelineDTO->id);

                if ($pipelineEntity === null) {
                    return Result::failure(
                        message: $this->translator->translate(key: 'pipelines.errors.pipeline_not_found'),
                        errorCode: self::ERROR_NOT_FOUND,
                    );
                }

                $updatedPipeline = $pipelineEntity->applyChanges(
                    name: $updatePipelineDTO->name,
                    isDefault: $updatePipelineDTO->isDefault,
                    stages: array_map($this->toStage(...), $updatePipelineDTO->stages),
                );

                $savedPipeline = $this->pipelineRepository->update($updatedPipeline);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($savedPipeline),
                    message: $this->translator->translate(key: 'pipelines.updated'),
                );
            },
            logMessage: 'Pipeline update failed.',
            fallbackMessageKey: 'pipelines.errors.update_failed',
            fallbackErrorCode: self::ERROR_UPDATE_FAILED,
            context: ['pipeline_id' => $updatePipelineDTO->id],
        );
    }

    /**
     * Runs a write use case inside the transaction boundary and converts its
     * failures into a Result. The callback throws on a violation after a write,
     * so a partial write is rolled back rather than committed.
     *
     * @param callable             $operation
     * @param string               $logMessage
     * @param string               $fallbackMessageKey
     * @param string               $fallbackErrorCode
     * @param array<string, mixed> $context
     *
     * @return Result
     */
    private function executeInTransaction(
        callable $operation,
        string $logMessage,
        string $fallbackMessageKey,
        string $fallbackErrorCode,
        array $context = [],
    ): Result {
        try {
            /** @var Result $result */
            $result = $this->transactionManager->run($operation);

            return $result;
        } catch (InvalidPipelineException $exception) {
            // A rejected pipeline rule is bad input, not a system failure.
            return Result::failure(
                message: $this->translator->translate(
                    key: $exception->translationKey,
                    replace: $exception->replace,
                ),
                errorCode: self::ERROR_INVALID,
            );
        } catch (PipelineDuplicateException $exception) {
            // run() already rolled back: the race lost to the unique index.
            return Result::failure(
                message: $this->translator->translate(key: $exception->translationKey),
                errorCode: self::ERROR_STAGE_CONFLICT,
            );
        } catch (\Throwable $exception) {
            $this->logger->error(message: $logMessage, context: array_merge($context, [
                'message' => $exception->getMessage(),
            ]));

            return Result::failure(
                message: $this->translator->translate(key: $fallbackMessageKey),
                errorCode: $fallbackErrorCode,
            );
        }
    }

    /**
     * Builds the stage the payload describes. The values reach the guard through
     * the value object, so a rejected stage never becomes part of a pipeline.
     *
     * @param StorePipelineStageDTO $storePipelineStageDTO
     *
     * @return PipelineStage
     *
     * @throws InvalidPipelineException When a stage value would break an invariant.
     */
    private function toStage(StorePipelineStageDTO $storePipelineStageDTO): PipelineStage
    {
        return PipelineStage::create(
            name: $storePipelineStageDTO->name,
            orderPosition: $storePipelineStageDTO->orderPosition,
            probability: $storePipelineStageDTO->probability,
        );
    }
}
