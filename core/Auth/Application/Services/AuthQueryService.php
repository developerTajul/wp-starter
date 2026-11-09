<?php

declare(strict_types=1);

namespace Core\Auth\Application\Services;

use Core\Auth\Application\Contracts\AuthQueryContract;
use Core\Auth\Application\Contracts\AuthQueryServiceContract;
use Core\Auth\Application\DTOs\Requests\LoginRequestDTO;
use Core\Auth\Application\DTOs\Requests\ProfileRequestDTO;
use Core\Auth\Application\Mappers\AuthResponseMapper;
use Core\Auth\Application\Mappers\ProfileResponseMapper;
use Core\User\Domain\Exceptions\InvalidUserException;
use Core\User\Domain\Exceptions\UserCannotAuthenticateException;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\PasswordHasherContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;

/**
 * Handles auth read use cases.
 *
 * @implements AuthQueryServiceContract
 */
final class AuthQueryService implements AuthQueryServiceContract
{
    private const ERROR_INVALID_CREDENTIALS = 'AUTH_INVALID_CREDENTIALS';

    private const ERROR_ACCOUNT_INACTIVE = 'AUTH_ACCOUNT_INACTIVE';

    private const ERROR_LOGIN_FAILED = 'AUTH_LOGIN_FAILED';

    private const ERROR_USER_NOT_FOUND = 'AUTH_USER_NOT_FOUND';

    private const ERROR_PROFILE_FETCH_FAILED = 'AUTH_PROFILE_FETCH_FAILED';

    /**
     * The wording a refused sign-in is reported with. One key serves both the
     * unknown-email and the wrong-password outcome, so the two cannot be told
     * apart from the response.
     */
    private const MESSAGE_INVALID_CREDENTIALS = 'auth.errors.invalid_credentials';

    /**
     * @param AuthQueryContract      $authQuery
     * @param AuthResponseMapper     $responseMapper
     * @param ProfileResponseMapper  $profileResponseMapper
     * @param PasswordHasherContract $passwordHasher
     * @param TranslatorContract     $translator
     * @param LoggerInterface        $logger
     */
    public function __construct(
        private readonly AuthQueryContract $authQuery,
        private readonly AuthResponseMapper $responseMapper,
        private readonly ProfileResponseMapper $profileResponseMapper,
        private readonly PasswordHasherContract $passwordHasher,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Verifies credentials and returns the account they belong to. The
     * account's own status decides whether it may sign in.
     *
     * The generic credential message avoids disclosing whether the email or the
     * password was wrong.
     *
     * @param LoginRequestDTO $loginDTO
     *
     * @return Result Contains the AuthResponseDTO on success.
     */
    public function login(LoginRequestDTO $loginDTO): Result
    {
        try {
            $userEntity = $this->authQuery->findByEmail($loginDTO->email);

            if ($userEntity === null) {
                return $this->invalidCredentials();
            }

            $passwordMatches = $userEntity->passwordMatches(
                $loginDTO->password,
                fn(string $plainPassword, string $storedHash) => $this->passwordHasher->verify(
                    plainPassword: $plainPassword,
                    hashedPassword: $storedHash,
                ),
            );

            if (! $passwordMatches) {
                return $this->invalidCredentials();
            }

            // The entity refuses the sign-in itself, so the status rule lives
            // there rather than in a comparison here. The returned instance is
            // used from here on, as the entity decides what a successful
            // sign-in leaves behind.
            $userEntity = $userEntity->assertCanSignIn();
        } catch (UserCannotAuthenticateException $exception) {
            // An inactive account is an ordinary outcome of a sign-in attempt,
            // not a system failure, so it is reported without being logged. The
            // refusal carries its own key and error code.
            return Result::failure(
                message: $this->translator->translate(
                    key: $exception->translationKey,
                ),
                errorCode: $exception->errorCode,
            );
        } catch (InvalidUserException $exception) {
            // Any other rejected rule is bad input rather than a failure.
            return Result::failure(
                message: $this->translator->translate(
                    key: $exception->translationKey,
                    replace: $exception->replace,
                ),
                errorCode: self::ERROR_ACCOUNT_INACTIVE,
            );
        } catch (\Throwable $exception) {
            $this->logger->error(message: 'User login failed.', context: [
                'message' => $exception->getMessage(),
            ]);

            return Result::failure(
                message: $this->translator->translate(key: 'auth.errors.login_failed'),
                errorCode: self::ERROR_LOGIN_FAILED,
            );
        }

        return Result::success(
            data: $this->responseMapper->toResponseDTO($userEntity),
            message: $this->translator->translate(key: 'auth.logged_in'),
        );
    }

    /**
     * Reads the profile of the given account.
     *
     * @param ProfileRequestDTO $profileDTO
     *
     * @return Result Contains the ProfileResponseDTO on success.
     */
    public function getProfile(ProfileRequestDTO $profileDTO): Result
    {
        try {
            $userEntity = $this->authQuery->findById($profileDTO->userId);

            // A missing account is an ordinary outcome of a read, so it is
            // reported rather than thrown.
            if ($userEntity === null) {
                return Result::failure(
                    message: $this->translator->translate(key: 'auth.errors.user_not_found'),
                    errorCode: self::ERROR_USER_NOT_FOUND,
                );
            }

            return Result::success(
                data: $this->profileResponseMapper->toResponseDTO($userEntity),
                message: $this->translator->translate(key: 'auth.profile_fetched'),
            );
        } catch (\Throwable $exception) {
            $this->logger->error(message: 'User profile fetch failed.', context: [
                'message' => $exception->getMessage(),
            ]);

            return Result::failure(
                message: $this->translator->translate(key: 'auth.errors.profile_fetch_failed'),
                errorCode: self::ERROR_PROFILE_FETCH_FAILED,
            );
        }
    }

    /**
     * Builds the failure reported when credentials do not match an account.
     * One place, so both outcomes carry the same wording and error code.
     *
     * @return Result
     */
    private function invalidCredentials(): Result
    {
        return Result::failure(
            message: $this->translator->translate(key: self::MESSAGE_INVALID_CREDENTIALS),
            errorCode: self::ERROR_INVALID_CREDENTIALS,
        );
    }
}
