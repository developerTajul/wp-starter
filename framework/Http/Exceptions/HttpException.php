<?php
declare(strict_types=1);

namespace Framework\Http\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a request or response cannot be built or sent.
 *
 * One exception type for the whole HTTP layer rather than one per failure mode:
 * every caller that catches it does the same thing - log it and let WordPress
 * produce an error page - so the distinction that matters is the message, not
 * the class. Subclass it if a failure ever needs a distinct catch.
 *
 * Status and headers
 *
 * An HTTP exception that cannot say what status it means is an exception the
 * handler has to guess about, which is why the adapters used to hard-code `500`
 * at every failure site. The status now travels *on the exception*, so the class
 * that knows what went wrong - a 404 from a resolver, a 405 from a method guard,
 * a 422 from a domain mapping - states it once and the handler renders it.
 *
 * The default is **500**, and that is the honest default: this class is most
 * often raised for "the framework could not do its job", which is a server
 * error. A caller raising it for a client mistake passes the status explicitly,
 * or uses {@see NotFoundHttpException} / {@see TooManyRequestsHttpException}.
 *
 * Why `$statusCode` is not `\Exception`'s `$code`
 *
 * They are different numbers and conflating them is a trap. `\Exception::$code`
 * is a developer-facing integer with no meaning the language assigns it; a
 * status is part of the HTTP contract. The constructor keeps them separate -
 * status second, code last - so `new HttpException($m, 404)` cannot be mistaken
 * for setting an exception code.
 *
 * Headers
 *
 * A 429 that cannot say `Retry-After`, or a 405 that cannot say `Allow`, is an
 * error response a correct client cannot act on. Headers are therefore carried
 * here rather than invented by the handler, which does not know what the failure
 * was. Values are strings, name => value, and the handler passes them through
 * untouched - emitting a header is the response's job, deciding it is this
 * class's caller's.
 */
class HttpException extends RuntimeException
{
    /**
     * Whether this instance was manufactured by the handler as a wrapper around a
     * non-HTTP throwable, rather than raised deliberately by application code.
     *
     * Why this cannot be inferred from `$previous`
     *
     * "Wraps something" looks like it would identify the wrapped case, and it does
     * not: {@see viewFailed()} is a *deliberate* 500 that wraps the render error
     * which caused it. Treating every exception with a previous as accidental
     * would make that one generic, and treating none of them that way would leak a
     * repository's `RuntimeException` message to the client.
     *
     * So the fact is recorded where it is known - at the wrap site - rather than
     * guessed at from a property that correlates with it without determining it.
     *
     * Deliberately not a constructor parameter: it is not part of what an author
     * raising an HTTP exception decides, and a signature that offered it would
     * invite an accidental `true`.
     *
     * @internal Set only by {@see Handler::toHttpException()}.
     */
    private bool $wrappedByHandler = false;

    /**
     * @param string                $message    What went wrong, for a developer.
     * @param int                   $statusCode The HTTP status this failure means.
     * @param array<string, string> $headers    Extra headers the response must carry.
     * @param int                   $code       The exception code, distinct from the status.
     * @param Throwable|null        $previous   The cause, when this wraps one.
     */
    public function __construct(
        string $message = '',
        private readonly int $statusCode = 500,
        private readonly array $headers = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Mark this exception as an accidental failure the handler wrapped.
     *
     * Returns `$this` so the wrap site can build and mark in one expression. The
     * mutation is confined to construction - the handler marks an instance it just
     * created and has not yet handed to anyone.
     *
     * @internal
     */
    public function markAsWrappedByHandler(): static
    {
        $this->wrappedByHandler = true;

        return $this;
    }

    /**
     * Whether the handler wrapped a non-HTTP throwable in this exception.
     *
     * True only for a server fault that arrived as something else - a repository's
     * `RuntimeException`, a `TypeError`. The handler hides the message of these in
     * production, because it is diagnostic text about the internals.
     */
    public function wasWrappedByHandler(): bool
    {
        return $this->wrappedByHandler;
    }

    /**
     * The HTTP status this failure should be rendered as.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Extra headers the error response must carry, name => value.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * A JSON request body that is not valid JSON.
     *
     * Deliberately exception-or-null on the Request side: a client that sends
     * `Content-Type: application/json` with a malformed body has made a caller
     * error, but only the application knows whether that is a 400 or a silent
     * fall back to form data. Request::json() throws; capture() on a broken body
     * still succeeds with just the other sources.
     */
    public static function malformedJson(string $message): self
    {
        return new self(sprintf(
            'The request body declares Content-Type: application/json but could not be decoded: %s',
            $message
        ));
    }

    /**
     * A view path that is not a readable file.
     *
     * A directory lands here too, since `is_file()` excludes it. An empty *file*
     * does not - it renders as an empty string, which is a legitimate (if
     * unhelpful) view rather than an error.
     *
     * Why the candidates are named
     *
     * A view *name* goes through two readings - dotted (`leads.index`) and
     * literal (`receipt.v2.php`) - and a name that misses under both is a name
     * whose author picked the wrong one. Reporting only the dotted path says
     * "there is no such file", which is true and unhelpful: the usual mistake is
     * a literal filename written with dots in it, whose dotted reading points at
     * a directory that was never meant. Naming both is what turns "not found"
     * into "you meant the other reading".
     *
     * The paths are optional because a caller that looked in exactly one place -
     * or that has a resolved path from elsewhere - still needs this to read
     * sensibly.
     */
    public static function viewNotFound(string $view, ?string $dotted = null, ?string $literal = null): self
    {
        $message = sprintf('View [%s] does not exist or is not a readable file.', $view);

        if ($dotted !== null) {
            $message .= sprintf(' Tried [%s]', $dotted);
        }

        if ($literal !== null && $literal !== $dotted) {
            $message .= sprintf(' and the literal filename [%s]', $literal);
        }

        return new self($message . '.');
    }

    /**
     * A view that raised while rendering.
     *
     * The throwable is wrapped rather than allowed to escape because the buffer
     * is already open: an exception out of an active `ob_start()` would leave
     * the captured output dangling in the buffer for whatever renders next.
     * ViewResponse closes the buffer in a `finally` and rethrows this.
     */
    public static function viewFailed(string $path, Throwable $previous): self
    {
        return new self(
            sprintf('View [%s] failed to render: %s', $path, $previous->getMessage()),
            500,
            [],
            0,
            $previous
        );
    }

    /**
     * A response that was already sent once.
     *
     * Sending twice would emit a second body after the first - and for a JSON
     * response, a second document - which is always a bug in the caller, not a
     * client error. Catching it here costs one bool and saves a corrupted page.
     */
    public static function alreadySent(string $response): self
    {
        return new self(sprintf('[%s] has already been sent.', $response));
    }
}
