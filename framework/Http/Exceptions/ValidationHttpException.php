<?php
declare(strict_types=1);

namespace Framework\Http\Exceptions;

/**
 * The request was well-formed and semantically wrong.
 *
 * The seam this class is half of
 *
 * The domain has a {@see \Core\Shared\Exceptions\DomainValidationException} that
 * knows nothing about HTTP. The HTTP layer needs a 422 carrying field errors.
 * Neither may import the other - `Core\` cannot depend on `Framework\`, and
 * putting a status code in the domain exception would make business rules depend
 * on a delivery convention.
 *
 * This class is the HTTP half of that pair, and
 * {@see Handler::render()} is the single line that joins them:
 *
 *     DomainValidationException  ->  ValidationHttpException (422)
 *
 * Why the mapping lives in the handler and not here
 *
 * It would be shorter to give `DomainValidationException` a `toHttp()` method, or
 * to have this class's constructor accept one. Both put the domain→HTTP decision
 * in a zone that should not hold it: the domain exception would gain a method
 * whose whole body is a delivery concern, and this class - which is meant to be
 * constructible by any HTTP-layer caller - would take a dependency pointing at
 * `Core\`.
 *
 * Keeping the mapping in the handler means there is exactly one place to read to
 * answer "what status does a violated invariant produce", and it is a place a
 * developer debugging a surprising 422 will already be looking. It also means a
 * project may map the same domain exception differently - a JSON API and an HTML
 * admin screen can disagree - without the domain exception changing.
 *
 * `errors` is the payload, not decoration
 *
 * A 422 whose body is only a message has told a client "something you sent was
 * wrong" and forced it to guess which field. The field map is what makes the
 * response actionable, and it is the same `array<string, list<string>>` shape
 * {@see \Core\Shared\Application\DTOs\Result} and the domain exception carry, so a client parses
 * one envelope whichever of the three produced the failure.
 */
final class ValidationHttpException extends HttpException
{
    /**
     * @param string                      $message The summary, for a human.
     * @param array<string, list<string>> $errors  Field name => messages.
     * @param string|null                 $errorCode A stable identifier for clients.
     * @param array<string, string>       $headers
     */
    public function __construct(
        string $message = 'The submitted data was not valid.',
        private readonly array $errors = [],
        private readonly ?string $errorCode = null,
        array $headers = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 422, $headers, 0, $previous);
    }

    /**
     * Field-level detail, keyed by field name.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * A stable identifier for the failure, or null when there is none.
     */
    public function errorCode(): ?string
    {
        return $this->errorCode;
    }
}
