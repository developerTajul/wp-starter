<?php

declare(strict_types=1);

namespace Core\Shared\Exceptions;

use DomainException;

/**
 * A rule the domain enforces was broken by the data it was handed.
 *
 * ## Why this is not an HTTP exception
 *
 * It carries no status code, no headers and no response shape — and that is the
 * point, not an omission. `Core\` is the inside of the hexagon: it is exercised
 * by tests with no CMS, no router and no request, and a type in here that knew
 * about `422` would make every domain rule depend on the protocol that happens to
 * be delivering it. The same violation reached from a console command, a queued
 * job or an admin screen would then have to mean something different or pretend
 * to be HTTP.
 *
 * The translation lives in exactly one place — the delivery layer's exception
 * handler, which maps this to a 422 with its field errors attached. See
 * `Framework\Http\Exceptions\Handler::mapDomainException()`.
 *
 * The golden rule is asserted rather than assumed: `HandlerTest` checks that this
 * class has no `statusCode()` method, so re-introducing one fails a test rather
 * than quietly re-coupling the layers.
 *
 * ## Why field errors are a map and not one message
 *
 * A form submitted with three bad fields should report three, and the client
 * needs to know *which* input each belongs to. A single flat message cannot say
 * that, and a client left to parse field names out of prose will get it wrong on
 * the first wording change. The keys are field names — the same ones the request
 * used — and the values are lists, because one field can break more than one rule
 * at once and collapsing them would hide all but the first.
 *
 * @see \Framework\Http\Exceptions\ValidationHttpException The HTTP projection.
 */
class DomainValidationException extends DomainException
{
    /**
     * The code used when a caller does not name one.
     *
     * A stable, machine-readable identifier so a client can branch on the *kind*
     * of failure without matching message text — which is localisable, and which
     * a translator is free to reword. It is deliberately generic: a caller that
     * has something more specific to say passes its own.
     */
    public const DEFAULT_ERROR_CODE = 'validation_failed';

    /**
     * @param string                        $message  A human-readable summary.
     * @param array<string, list<string>>   $errors   Field name => what is wrong
     *                                                with it.
     * @param string                        $errorCode A stable identifier for
     *                                                clients to branch on.
     * @param \Throwable|null               $previous
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        private readonly string $errorCode = self::DEFAULT_ERROR_CODE,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A violation of one field.
     *
     * The common case by a wide margin, and the one worth a named constructor:
     * every call site that builds a single-field violation by hand has to
     * remember to wrap the message in a list under the field name, and the one
     * that forgets produces a payload whose shape depends on which rule fired.
     *
     * @param string      $field
     * @param string      $message What is wrong with it.
     * @param string|null $code    Defaults to {@see DEFAULT_ERROR_CODE}.
     */
    public static function field(string $field, string $message, ?string $code = null): self
    {
        return new self(
            message: $message,
            errors: [$field => [$message]],
            errorCode: $code ?? self::DEFAULT_ERROR_CODE,
        );
    }

    /**
     * A violation spanning several fields at once.
     *
     * @param array<string, list<string>> $errors
     * @param string                      $message A summary; callers pass one
     *                                               because "validation failed"
     *                                               alone tells a client nothing
     *                                               it cannot read from `errors`.
     */
    public static function fields(array $errors, string $message, ?string $code = null): self
    {
        return new self(
            message: $message,
            errors: $errors,
            errorCode: $code ?? self::DEFAULT_ERROR_CODE,
        );
    }

    /**
     * What is wrong, per field.
     *
     * Always a list per field, never a bare string: a consumer that iterates and
     * a consumer that reads only in the same shape, and one field breaking two
     * rules reports both rather than the first.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Whether any field-level detail was given at all.
     *
     * A violation raised by a rule that is not about a particular field has none,
     * and a handler that always emitted an `errors` block would give a client an
     * empty object to render as a form with no fields highlighted.
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * The stable identifier for this kind of failure.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
