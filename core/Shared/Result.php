<?php
declare(strict_types=1);

namespace Core\Shared;

/**
 * The outcome of a use case: either a value, or a reason it did not happen.
 *
 * ## Why this exists
 *
 * A service method that returns `Lead|null` or `true|false` has told the caller
 * *that* something failed and nothing else. The caller then has to guess why —
 * was the record missing, was the caller not allowed to touch it, did validation
 * reject the input? Those are three different HTTP statuses, three different
 * messages to the user, and three different things the caller should do next,
 * and every one of them was flattened into one indistinguishable `null`.
 *
 * Exceptions are the other reflex, and they are the wrong tool for this. An
 * exception says "this was not supposed to happen". A missing row, a rejected
 * form, a permission the user does not have — those are the *ordinary* outcomes
 * of an application, and expressing them as throws means every caller either
 * wraps every call in try/catch or lets a routine rejection escape as a 500.
 *
 * So a `Result` is a return value that carries the reason. The service states
 * what happened, in one type, and the caller decides how this layer reports it.
 *
 * ## Why the status code is here rather than in the controller
 *
 * It is the part that looks like a layering mistake, so it is worth being
 * explicit: `422` is not an HTTP concept leaking inward, it is the *category* of
 * the failure, and HTTP happens to have standardised names for those categories.
 * `422` means "the input was well-formed and semantically wrong"; `404` means
 * "there is no such thing"; `403` means "it exists and you may not touch it".
 * A service that returns only a message string has made the controller infer the
 * category by string-matching, which is how the same failure ends up as a 400 on
 * one route and a 422 on another.
 *
 * The code is a plain `int` with no HTTP dependency attached: no constants
 * imported from a framework, no response object, nothing that would stop this
 * class being used from a console command or a queue worker where there is no
 * request at all. A caller that wants to ignore it can.
 *
 * ## Shape
 *
 *     return Result::ok($lead);
 *     return Result::fail('No lead with that id.', 404);
 *     return Result::validation(['email' => ['That address is already in use.']]);
 *
 * `ok()` is the only constructor that carries a value, and `fail()` the only one
 * that carries a message — so a `Result` cannot be "successful with an error" or
 * "failed with no explanation". The failure paths that share a status have named
 * constructors ({@see notFound()}, {@see forbidden()}, {@see validation()})
 * because the status is the part that is easy to get inconsistently wrong.
 *
 * ## What it deliberately does not do
 *
 * No `unwrap()`, no `map()`, no monadic chaining. This is a value that travels
 * one hop — from a use case to the caller that renders it — and a fluent API
 * would invite the business logic to spread across the seams instead of living
 * in the service. Read the flag, read the field you need, carry on.
 */
final class Result
{
    /**
     * The HTTP-equivalent category of a failure that has no better description.
     *
     * `400` rather than `500`: a `Result` is only ever constructed deliberately,
     * at a point where the service has decided the input cannot be acted on. A
     * surprise is a throw, not a Result — see the class docblock.
     */
    public const DEFAULT_ERROR_STATUS = 400;

    /**
     * Declared rather than promoted so that the constructor can enforce the
     * invariants below before anything is assigned.
     *
     * @param mixed $value
     */
    private function __construct(
        private readonly bool $ok,
        private readonly mixed $value,
        private readonly string $message,
        private readonly int $status,
        private readonly array $errors,
        private readonly ?string $errorCode,
    ) {
    }

    /**
     * A successful outcome, optionally carrying a value.
     *
     * `$value` is `mixed` because the same type reports "here is the note" and
     * "here is nothing, it worked" — a delete returns `Result::ok()`. A union of
     * entity types would have to grow a member for every use case, and the one
     * thing every caller does with the value is hand it to a resource mapper.
     *
     * @param mixed $value
     */
    public static function ok(mixed $value = null): self
    {
        return new self(true, $value, '', self::DEFAULT_ERROR_STATUS, [], null);
    }

    /**
     * A failure with a message for the human who caused it, and a category.
     *
     * `$status` is validated rather than trusted, because the value reaches an
     * HTTP response and a caller that passes `0` or `99` produces a response no
     * client can interpret. Refusing here points at the service that supplied it.
     *
     * @param array<string, list<string>> $errors Field-level detail, when there is any.
     * @param string|null                 $errorCode A stable machine-readable
     *                                              identifier — an i18n key, or
     *                                              the code an API client switches
     *                                              on. Not the message: messages
     *                                              get reworded, and a client
     *                                              matching on one breaks silently
     *                                              when they do.
     */
    public static function fail(
        string $message,
        int $status = self::DEFAULT_ERROR_STATUS,
        array $errors = [],
        ?string $errorCode = null
    ): self {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException(sprintf(
                'A Result status must be a valid HTTP status code; %d was given.',
                $status
            ));
        }

        if ($message === '') {
            throw new \InvalidArgumentException(
                'A failed Result must carry a message. A failure with no explanation cannot be '
                . 'reported to anyone, which is the situation Result exists to prevent.'
            );
        }

        return new self(false, null, $message, $status, $errors, $errorCode);
    }

    /**
     * "There is no such thing."
     *
     * The message is supplied by the caller rather than defaulted here: this
     * layer has no idea what the missing thing is called, and a generic "Not
     * found." that a user reads without context is worse than the caller having
     * to write one sentence.
     */
    public static function notFound(string $message = 'Not found.'): self
    {
        return self::fail($message, 404, [], 'not_found');
    }

    /**
     * "It exists, and you may not touch it."
     *
     * Distinct from `401` (`notFound()`'s sibling {@see unauthenticated()}),
     * which means "I do not know who you are". Collapsing the two is a common
     * mistake: a client cannot tell from a `401` whether logging in would help.
     */
    public static function forbidden(string $message = 'You may not do that.'): self
    {
        return self::fail($message, 403, [], 'forbidden');
    }

    public static function unauthenticated(string $message = 'You must be signed in.'): self
    {
        return self::fail($message, 401, [], 'unauthenticated');
    }

    /**
     * "Well-formed input, semantically rejected."
     *
     * The one failure constructor whose detail is the point: a validation failure
     * that reports only "The form was invalid." forces the client to guess which
     * field, and the per-field messages are already in hand at the call site.
     *
     * @param array<string, list<string>> $errors Field name => the problems with it.
     *                                            A list per field because a field
     *                                            can fail more than one rule, and
     *                                            reporting only the first makes the
     *                                            user fix them one submission apart.
     */
    public static function validation(
        array $errors,
        string $message = 'The given data was invalid.'
    ): self {
        if ($errors === []) {
            throw new \InvalidArgumentException(
                'A validation failure must name at least one field; an empty error map is '
                . 'indistinguishable from a generic failure, which is what this constructor '
                . 'exists to express.'
            );
        }

        return self::fail($message, 422, $errors, 'validation_failed');
    }

    /**
     * "The operation clashes with the current state."
     *
     * A duplicate, a stale version, a record another request is holding.
     * Separated from `409`'s neighbours because the client's correct response is
     * to re-read and retry, which no other status here implies.
     */
    public static function conflict(string $message = 'That conflicts with the current state.'): self
    {
        return self::fail($message, 409, [], 'conflict');
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isFailure(): bool
    {
        return !$this->ok;
    }

    /**
     * The carried value, or null on failure.
     *
     * `null` is a legitimate *success* value — a delete returns `Result::ok()` —
     * so this cannot be used to test the outcome. Use {@see isOk()}: a caller
     * testing `=== null` to detect failure would misread every successful
     * operation that returns nothing.
     *
     * @return mixed
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * The carried value, or a thrown error if there is none.
     *
     * The counterpart to the factory: a caller that has already checked
     * {@see isOk()} and wants the value without a null check at each use. It
     * throws rather than asserting because the check it replaces would have been
     * a guard, and a guard that is skipped is a bug — not a state to report.
     *
     * ## The signature has to count its arguments, and that is not a quirk
     *
     * `valueOr()` with no argument must throw, and `valueOr(null)` must return
     * null — those are different requests, and a `mixed $default = null`
     * parameter cannot tell them apart. Sending both to the same branch means
     * either the no-argument call starts returning null silently, or a caller who
     * deliberately passed null gets an exception for a state they asked for.
     *
     * `func_num_args()` is what keeps them distinct. The alternative — a private
     * sentinel object as the parameter's default — is the same trick with more
     * machinery, and it puts a value in the signature's type that no caller is
     * meant to construct.
     *
     * @param mixed $default Returned instead of throwing, when the argument was
     *                       actually supplied.
     *
     * @return mixed
     */
    public function valueOr(mixed $default = null): mixed
    {
        if (!$this->ok && func_num_args() === 0) {
            throw new \LogicException(sprintf(
                'Result::valueOr() was called on a failed Result with no default. Check isOk() '
                . 'first, or pass a default. The failure was: %s',
                $this->message
            ));
        }

        return $this->ok ? $this->value : $default;
    }

    /**
     * Why it failed, or the empty string on success.
     *
     * Empty rather than null so that a caller building a response can put it
     * straight into a payload field without a null check. An empty message in a
     * response is a visible, harmless nothing; the string `null` in a JSON
     * payload is neither.
     */
    public function message(): string
    {
        return $this->message;
    }

    /**
     * The failure's category. On success this is {@see DEFAULT_ERROR_STATUS},
     * which is meaningless by construction — read it only under {@see isFailure()}.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Field-level detail from {@see validation()}, empty for every other outcome.
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
     * The stable machine-readable identifier for this failure, when one was given.
     *
     * Nullable because most failures do not need one. A client that switches on
     * {@see status()} plus {@see errors()} rarely needs more, and inventing a code
     * for every branch produces a vocabulary nobody maintains.
     */
    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * A failure as a plain array an error-shaped response can be built from.
     *
     * Not every application has an error type to convert into — a REST adapter
     * does, a console command does not — so this returns data in a fixed shape
     * and lets the caller wrap it in whatever the transport wants.
     *
     * It exists so the mapping from a domain failure to a transport error is
     * written once rather than at each call site that renders one, which is how
     * two endpoints end up reporting the same failure differently.
     *
     * Deliberately transport-neutral: no class names, no framework types, only
     * arrays and scalars. The `code` is a stable string rather than a numeric
     * constant because a transport that has its own numbering will want to map
     * this onto its own, and a number that already looks like one of its own is
     * the version of this that gets used as-is by mistake.
     *
     * @return array{code: string, message: string, data: array{status: int, errors: array<string, list<string>>}}|null
     */
    public function toErrorPayload(): ?array
    {
        if ($this->ok) {
            return null;
        }

        return [
            'code' => $this->errorCode ?? 'result_failed',
            'message' => $this->message,
            'data' => [
                'status' => $this->status,
                'errors' => $this->errors,
            ],
        ];
    }
}
