<?php
declare(strict_types=1);

namespace Framework\Http\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a request or response cannot be built or sent.
 *
 * One exception type for the whole HTTP layer rather than one per failure mode:
 * every caller that catches it does the same thing — log it and let WordPress
 * produce an error page — so the distinction that matters is the message, not
 * the class. Subclass it if a failure ever needs a distinct catch.
 */
class HttpException extends RuntimeException
{
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
     * does not — it renders as an empty string, which is a legitimate (if
     * unhelpful) view rather than an error.
     *
     * ## Why the candidates are named
     *
     * A view *name* goes through two readings — dotted (`leads.index`) and
     * literal (`receipt.v2.php`) — and a name that misses under both is a name
     * whose author picked the wrong one. Reporting only the dotted path says
     * "there is no such file", which is true and unhelpful: the usual mistake is
     * a literal filename written with dots in it, whose dotted reading points at
     * a directory that was never meant. Naming both is what turns "not found"
     * into "you meant the other reading".
     *
     * The paths are optional because a caller that looked in exactly one place —
     * or that has a resolved path from elsewhere — still needs this to read
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
            0,
            $previous
        );
    }

    /**
     * A response that was already sent once.
     *
     * Sending twice would emit a second body after the first — and for a JSON
     * response, a second document — which is always a bug in the caller, not a
     * client error. Catching it here costs one bool and saves a corrupted page.
     */
    public static function alreadySent(string $response): self
    {
        return new self(sprintf('[%s] has already been sent.', $response));
    }
}
