<?php
declare(strict_types=1);

namespace Framework\Http\Responses;

use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Exceptions\HttpException;

/**
 * A finished HTML string, sent as a page.
 *
 * Why this exists alongside ViewResponse
 *
 * {@see ViewResponse} renders a PHP file. This one carries a string that is
 * already rendered - what a controller returns when it has built markup itself,
 * or when a route's action returns a plain string and the dispatcher has to wrap
 * it in something sendable.
 *
 * That wrapping is the reason the class earns its place: without it a route
 * returning `'<p>Saved.</p>'` has nothing to send, and the alternatives are both
 * worse - coercing it to JSON emits a quoted string with the wrong content type,
 * and echoing it directly skips the status and the headers and leaves the
 * terminal `send()` contract unfulfilled. The HTTP contract is that a response
 * knows its own status; a bare string does not, so it is given one here.
 *
 * What it deliberately does not do
 *
 * No escaping. The content is treated as finished HTML, because the caller
 * either produced markup deliberately or has already escaped its data - escaping
 * here would double-encode a view's output and make `&amp;` render literally.
 * Escaping belongs where the data is interpolated, not at the point of sending.
 *
 * No `exit`. `ViewResponse` does not terminate either, and a web route's
 * termination is the dispatcher's decision: an admin screen must return so
 * WordPress can render the surrounding chrome, while a front-end page ends the
 * request. A response that killed the process could not serve the first.
 */
final class HtmlResponse implements ResponseInterface
{
    private bool $sent = false;

    /**
     * @param string                $html    The body, already rendered and escaped as needed.
     * @param int                   $status  HTTP status code to send.
     * @param array<string, string> $headers Extra headers, name => value.
     */
    public function __construct(
        private readonly string $html,
        private readonly int $status = 200,
        private readonly array $headers = []
    ) {
    }

    /**
     * @throws HttpException When this response has already been sent.
     */
    public function send(): void
    {
        if ($this->sent) {
            throw HttpException::alreadySent(self::class);
        }

        $this->sent = true;

        $this->sendHeaders();

        echo $this->html;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function html(): string
    {
        return $this->html;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withStatus(int $status): self
    {
        return $this->with([], $status);
    }

    /**
     * A copy of this response with headers and/or a status added.
     *
     * Returns a new instance rather than mutating, matching
     * {@see JsonResponse::with()} - a response is often held by more than one
     * caller, and mutating one in place would change it for all of them.
     *
     * @param array<string, string> $headers
     */
    public function with(array $headers = [], ?int $status = null): self
    {
        return new self(
            $this->html,
            $status ?? $this->status,
            array_replace($this->headers, $headers)
        );
    }

    /**
     * Emit the status line and headers.
     *
     * Guarded on `headers_sent()` because a controller may have produced output
     * before this response was built - a stray `echo`, a deprecation notice with
     * display_errors on - and calling the header functions anyway raises a
     * warning that the suite turns into a failure and a production log turns into
     * noise. The body is still sent; only the headers are skipped.
     *
     * `status_header()` is WordPress's own, and is preferred over
     * `http_response_code()` where it exists: it also sets the reason phrase and
     * routes through WordPress's filters, so a status set here stays consistent
     * with the rest of the request. Outside WordPress it falls back, which is what
     * lets this class be tested without the CMS loaded.
     */
    private function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        if ($this->status !== 200) {
            if (function_exists('status_header')) {
                status_header($this->status);
            } else {
                http_response_code($this->status);
            }
        }

        foreach ($this->headers as $name => $value) {
            header(sprintf('%s: %s', $name, $value), true);
        }
    }
}
