<?php

declare(strict_types=1);

namespace Core\Shared\Application\DTOs;

use InvalidArgumentException;
use LogicException;

/**
 * The outcome of one use case: either a value, or a reason there is none.
 *
 * ## Why an outcome type instead of exceptions for expected failures
 *
 * "That email is already taken" is not an exceptional event — it is one of the
 * answers `createLead()` has, and the caller is expected to render it. Throwing
 * makes the ordinary path the expensive one, and it makes the method's real
 * contract invisible: a signature that returns `LeadResponseDTO` and documents
 * "throws on duplicate" hides the second half of its return type in prose.
 * Returning `Result` puts both answers in the type.
 *
 * Exceptions are still used — for the genuinely unexpected. What
 * {@see \Core\Lead\Application\Services\LeadCommandService} catches out of a
 * transaction becomes a `Result`, so the delivery layer only ever branches on
 * one shape.
 *
 * ## Why this class knows about HTTP statuses and still is not HTTP
 *
 * This is the question the `status()` method invites, so it is worth answering
 * rather than leaving to be re-litigated.
 *
 * `status()` is a *classification*, not a transport. It exists so the delivery
 * layer can project an outcome onto a protocol without re-deriving, from message
 * text, what kind of failure it is holding: a transport that had to guess
 * "not_found" from prose would get it wrong on the first wording change, and
 * every layer above would then carry a copy of the same `match`. The field is
 * called a status rather than a response because no response is built here —
 * nothing is serialised, no headers are chosen, no `Response` object is
 * constructed.
 *
 * The cost of that choice is real and is confined deliberately: `Core\` now
 * names integers that the HTTP specification happens to own. The alternative —
 * a private vocabulary of outcome kinds translated in the delivery layer — was
 * rejected because it would need a second mapping for console and queue
 * delivery anyway, and the numbers are the one vocabulary every one of those
 * transports already agrees on.
 *
 * `errorCode()` is the part a client is meant to branch on. Statuses are coarse
 * — several distinct failures share a 409 — and the code is what separates them
 * without matching localisable message text. The two are kept apart for exactly
 * that reason, and {@see toErrorPayload()} is asserted to keep them apart.
 *
 * ## Why `status()` is the fifth constructor argument and not the second
 *
 * `fail($message, $status)` reads well, and the four-argument form is what the
 * entity `withId()` factories call positionally. Inserting
 * `$errors` in front of `$status` would have made that call pass an array of
 * field errors as a status code — silently, since PHP coerces nothing here and
 * the argument would simply be wrong. The order below is the one the existing
 * call sites already use.
 */
final readonly class Result
{
    /**
     * The status a failure gets when the caller names none.
     *
     * 400 rather than 500: `fail()` is reached by a caller describing something
     * the *request* did wrong — a malformed id, an unstorable value — and the
     * default should not accuse the server of a fault nobody has evidence for.
     * A genuine server fault is raised as an exception and mapped by the error
     * handler, not built here.
     */
    public const DEFAULT_ERROR_STATUS = 400;

    /**
     * The code attached to a failure that named none.
     *
     * Published rather than inlined so the delivery layer and the tests agree on
     * the fallback by name; see {@see toErrorPayload()}, where it is the value a
     * client switches on when the outcome carried no specific code.
     */
    public const DEFAULT_GENERIC_CODE = 'result_failed';

    public const ERROR_VALIDATION = 'validation_failed';

    public const ERROR_NOT_FOUND = 'not_found';

    public const ERROR_FORBIDDEN = 'forbidden';

    public const ERROR_UNAUTHENTICATED = 'unauthenticated';

    public const ERROR_CONFLICT = 'conflict';

    /**
     * @param bool                          $success Whether this outcome carries a value.
     * @param mixed                         $value   The value, or null on a failure.
     * @param string                        $message A human-readable summary; never
     *                                               empty on a failure.
     * @param array<string, list<string>>   $errors  Field name => what is wrong with
     *                                               it, for validation failures.
     * @param int                           $status  The classification, see the class
     *                                               docblock.
     * @param string|null                   $errorCode A stable identifier for clients.
     */
    private function __construct(
        private bool $success,
        private mixed $value,
        private string $message,
        private array $errors,
        private int $status,
        private ?string $errorCode,
    ) {
    }

    // ------------------------------------------------------------- construction

    /**
     * An outcome that carries a value.
     *
     * `$value` is optional because a use case that changes something and returns
     * nothing is still a success. If it were required, every delete would have to
     * invent a placeholder, and the placeholder would eventually be read.
     */
    public static function ok(mixed $value = null): self
    {
        return new self(
            success: true,
            value: $value,
            message: '',
            errors: [],
            status: 200,
            errorCode: null,
        );
    }

    /**
     * The success spelling used by the service layer.
     *
     * Two names for one outcome, because two vocabularies are already in use and
     * each reads better where it is found: `ok()` is what a domain method returns
     * — "the entity is fine" — and `success()` is what a use case returns, with
     * the message that will reach the user. Collapsing them would force one of
     * the two to read wrong at every call site.
     */
    public static function success(mixed $data = null, string $message = ''): self
    {
        return new self(
            success: true,
            value: $data,
            message: $message,
            errors: [],
            status: 200,
            errorCode: null,
        );
    }

    /**
     * A generic failure.
     *
     * `$errors` sits second and `$status` fourth to match the existing positional
     * call sites; see the class docblock for why that order is load-bearing
     * rather than incidental.
     *
     * @param array<string, list<string>> $errors
     */
    public static function fail(
        string $message,
        int $status = self::DEFAULT_ERROR_STATUS,
        array $errors = [],
        ?string $errorCode = null,
    ): self {
        if (trim($message) === '') {
            throw new InvalidArgumentException(
                'A failed Result must carry a message; a failure a user cannot be told about '
                . 'is indistinguishable from a bug.'
            );
        }

        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(sprintf(
                '[%d] is not a valid HTTP status code. A failure whose classification is wrong '
                . 'is worse than one with no classification, because the caller will trust it.',
                $status
            ));
        }

        return new self(
            success: false,
            value: null,
            message: $message,
            errors: $errors,
            status: $status,
            errorCode: $errorCode,
        );
    }

    /**
     * The failure spelling used by the service layer.
     *
     * Deliberately the mirror of {@see success()}: both are called with named
     * arguments at every service call site, so the two halves of one decision
     * read the same and a reader can see the message and code without counting
     * positions.
     */
    public static function failure(string $message, ?string $errorCode = null): self
    {
        return self::fail($message, self::DEFAULT_ERROR_STATUS, [], $errorCode);
    }

    // ------------------------------------------------------ named failures

    /**
     * The thing asked for does not exist.
     *
     * @param string $message Defaults to a message that names no domain noun,
     *                        because this class has none to name.
     */
    public static function notFound(string $message = 'The requested resource was not found.'): self
    {
        return self::fail($message, 404, [], self::ERROR_NOT_FOUND);
    }

    /**
     * The caller is known, and may not do this.
     */
    public static function forbidden(string $message = 'You are not allowed to do that.'): self
    {
        return self::fail($message, 403, [], self::ERROR_FORBIDDEN);
    }

    /**
     * The caller is not known at all.
     *
     * Separate from {@see forbidden()} on purpose: a client cannot tell from a
     * 401 whether logging in would help, and cannot tell from a 403 whether it
     * should try at all. Collapsing the two is the bug these two methods exist to
     * prevent.
     */
    public static function unauthenticated(string $message = 'Authentication is required.'): self
    {
        return self::fail($message, 401, [], self::ERROR_UNAUTHENTICATED);
    }

    /**
     * The request conflicts with the current state of the thing it targets.
     */
    public static function conflict(string $message = 'The request conflicts with the current state.'): self
    {
        return self::fail($message, 409, [], self::ERROR_CONFLICT);
    }

    /**
     * One or more fields were rejected.
     *
     * Always 422 and always with field errors attached, because a validation
     * failure with no field errors gives the delivery layer nothing to build a
     * form response from — and a client that has to parse field names out of a
     * summary message will get it wrong on the first wording change.
     *
     * @param array<string, list<string>> $errors Field name => every message for it.
     */
    public static function validation(
        array $errors,
        string $message = 'The given data was invalid.',
        ?string $errorCode = null,
    ): self {
        if ($errors === []) {
            throw new InvalidArgumentException(
                'A validation Result must carry at least one field error; without one it is '
                . 'indistinguishable from a generic failure, which is the one thing this '
                . 'constructor exists to express.'
            );
        }

        return self::fail($message, 422, $errors, $errorCode ?? self::ERROR_VALIDATION);
    }

    // ---------------------------------------------------------------- reading

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return ! $this->success;
    }

    /**
     * The positive spelling of {@see isSuccess()}.
     *
     * Present because the guard clauses read as the question they ask —
     * `if ($built->isFailure())` is rare next to `if ($result->isOk())` — and
     * because `isFailure()` alone would push call sites into negations.
     */
    public function isOk(): bool
    {
        return $this->success;
    }

    /**
     * The value, or null on a failure.
     *
     * Null rather than a throw, which is the deliberate half of a pair: a failed
     * outcome has no value, and the callers that read it — a service after
     * checking `isFailure()`, the lead command services reading a stored id —
     * have already established that the value is there. Throwing would turn a
     * branch they took into a crash.
     *
     * The protected read for the case where a null really is a bug is
     * {@see valueOr()}, which throws when called with no argument at all. That
     * method is where the "you forgot to check" guard lives, because it can tell
     * the two requests apart and this one cannot.
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * The value, or a default for a failure.
     *
     * ## Why this counts its arguments
     *
     * `mixed $default = null` cannot tell "no default given" from "the default is
     * null", and the difference is the whole point: with the single-branch
     * version, `valueOr()` on a failure could not throw, so a programming error
     * silently became `null`. Reading `func_num_args()` separates the two
     * requests, and it is the only thing standing between this method and that
     * regression.
     *
     * An explicit `null` is therefore honoured as a real default, and a success
     * whose value is legitimately null returns null rather than the default —
     * the default is consulted for failures, not for nulls.
     */
    public function valueOr(mixed $default = null): mixed
    {
        if ($this->success) {
            return $this->value;
        }

        if (func_num_args() === 0) {
            throw new LogicException(
                'valueOr() was called on a failed Result with no default and no arguments; '
                . 'either supply a default or check isOk() first.'
            );
        }

        return $default;
    }

    /**
     * The value under its service-layer name.
     *
     * `getData()` is what the lead services call and `value()` is what the note
     * domain calls; both are the same read. Kept as two names rather than
     * renaming one call site's worth, because the two vocabularies are already
     * established on either side of the module boundary.
     */
    public function getData(): mixed
    {
        return $this->value;
    }

    /**
     * The human-readable summary. Empty on a success, which is what lets
     * {@see toErrorPayload()} decide from presence rather than from a flag.
     */
    public function message(): string
    {
        return $this->message;
    }

    /**
     * {@see message()} under its service-layer name.
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Every field error, keyed by field name.
     *
     * Empty on anything but a validation failure, including on a success — the
     * delivery layer checks {@see hasErrors()} before emitting an `errors` block
     * so a client never receives an empty object to render as a form with no
     * fields highlighted.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * The classification. See the class docblock for what it is and is not.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * The stable identifier for clients to branch on, or null when none was
     * given. Nullable here and non-null in {@see toErrorPayload()}, which is what
     * keeps the payload's shape stable for a transport.
     */
    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The error projection a transport builds a response from, or null on a
     * success.
     *
     * One door out, so the shape is decided once. The transport is what turns
     * this into a response — this method returns an array and knows nothing about
     * how it will be serialised, which is the boundary the class docblock argues
     * for.
     *
     * The generic code is substituted here rather than at construction, so
     * `errorCode()` can stay honestly nullable for callers doing their own
     * branching while the payload keeps a value to switch on.
     *
     * @return array{code: string, message: string, data: array{status: int, errors: array<string, list<string>>}}|null
     */
    public function toErrorPayload(): ?array
    {
        if ($this->success) {
            return null;
        }

        return [
            'code' => $this->errorCode ?? self::DEFAULT_GENERIC_CODE,
            'message' => $this->message,
            'data' => [
                'status' => $this->status,
                'errors' => $this->errors,
            ],
        ];
    }
}
