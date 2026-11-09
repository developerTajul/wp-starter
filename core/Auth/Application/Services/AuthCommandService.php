<?php

declare(strict_types=1);

namespace Core\Auth\Application\Services;

use Core\Auth\Application\Contracts\AuthCommandServiceContract;
use Core\Auth\Application\DTOs\Requests\RegisterRequestDTO;
use Core\Auth\Application\Mappers\AuthResponseMapper;
use Core\Auth\Domain\Contracts\AuthRepositoryContract;
use Core\Auth\Domain\Exceptions\AuthDuplicateException;
use Core\User\Domain\Entities\UserEntity;
use Core\User\Domain\Exceptions\InvalidUserException;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\EventDispatcherContract;
use Core\Shared\Domain\Contracts\PasswordHasherContract;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;

/**
 * Handles auth write use cases.
 *
 * @implements AuthCommandServiceContract
 */
final class AuthCommandService implements AuthCommandServiceContract
{
    private const ERROR_INVALID = 'AUTH_INVALID';

    private const ERROR_ALREADY_EXISTS = 'AUTH_EMAIL_ALREADY_EXISTS';

    private const ERROR_REGISTRATION_FAILED = 'AUTH_REGISTRATION_FAILED';

    /**
     * @param AuthRepositoryContract     $authRepository
     * @param AuthResponseMapper         $responseMapper
     * @param TransactionManagerContract $transactionManager
     * @param EventDispatcherContract    $eventDispatcher
     * @param PasswordHasherContract     $passwordHasher
     * @param TranslatorContract         $translator
     * @param LoggerInterface            $logger
     */
    public function __construct(
        private readonly AuthRepositoryContract $authRepository,
        private readonly AuthResponseMapper $responseMapper,
        private readonly TransactionManagerContract $transactionManager,
        private readonly EventDispatcherContract $eventDispatcher,
        private readonly PasswordHasherContract $passwordHasher,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Registers a new account inside a transaction.
     *
     * The entity is built through its named constructor, so the name, email and
     * credential invariants run before anything reaches the table.
     *
     * @param RegisterRequestDTO $registerDTO
     *
     * @return Result Contains the AuthResponseDTO on success.
     */
    public function register(RegisterRequestDTO $registerDTO): Result
    {
        $events = [];

        $result = $this->executeInTransaction(
            operation: function () use ($registerDTO, &$events): Result {
                // The entity guards its own invariants; the service only hands it
                // an already-hashed credential.
                $userEntity = UserEntity::register(
                    name: $registerDTO->name,
                    email: $registerDTO->email,
                    password: $this->passwordHasher->hash($registerDTO->password),
                    role: $registerDTO->role,
                    status: $registerDTO->status,
                );

                $events = $userEntity->releaseEvents(); // BEFORE persisting

                $savedUser = $this->authRepository->create($userEntity);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($savedUser),
                    message: $this->translator->translate(key: 'auth.registered'),
                );
            },
            logMessage: 'User registration failed.',
            fallbackMessageKey: 'auth.errors.registration_failed',
            fallbackErrorCode: self::ERROR_REGISTRATION_FAILED,
        );

        if (! $result->isFailure()) {
            $this->dispatchEvents($events);
        }

        return $result;
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
        } catch (InvalidUserException $exception) {
            // A rejected account rule is bad input, not a system failure. The
            // exception's own wording is developer-facing, so its rule key is
            // resolved instead of the message being surfaced verbatim.
            return Result::failure(
                message: $this->translate(
                    translationKey: $exception->translationKey,
                    replace: $exception->replace,
                ),
                errorCode: self::ERROR_INVALID,
            );
        } catch (AuthDuplicateException $exception) {
            // run() already rolled back: the race lost to the unique index.
            return Result::failure(
                message: $this->translator->translate(key: $exception->translationKey),
                errorCode: self::ERROR_ALREADY_EXISTS,
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
     * Resolves a domain exception's wording. A replacement that is itself a
     * translation key is resolved too, so nested wording stays in the lang file.
     *
     * @param string                $translationKey
     * @param array<string, string> $replace
     *
     * @return string
     */
    private function translate(string $translationKey, array $replace = []): string
    {
        $resolvedReplace = array_map(
            callback: fn(string $value) => str_contains($value, '.') ? $this->translator->translate(key: $value) : $value,
            array: $replace,
        );

        return $this->translator->translate(key: $translationKey, replace: $resolvedReplace);
    }

    /**
     * Dispatches the events a committed use case raised. A dispatch failure is
     * logged and swallowed: the write is already committed, so rolling it back
     * for a listener's sake would lose the very data the event described.
     *
     * @param array<int, object> $events
     *
     * @return void
     */
    private function dispatchEvents(array $events): void
    {
        try {
            foreach ($events as $event) {
                $this->eventDispatcher->dispatch(event: $event);
            }
        } catch (\Throwable $exception) {
            $this->logger->error(message: 'Auth events failed after commit', context: [
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
