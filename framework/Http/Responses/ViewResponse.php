<?php
declare(strict_types=1);

namespace Framework\Http\Responses;

use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Exceptions\HttpException;
use Throwable;

/**
 * A rendered PHP view file.
 *
 * ## Why output buffering rather than `include`
 *
 * A view is a PHP file that echoes. Including it directly would write straight
 * into the response, which makes three things impossible: sending a status code
 * or a header *after* deciding what the view says, capturing the view to embed
 * it in a larger document, and asserting on the output in a test. Buffering
 * gives all three, and costs one function call.
 *
 * ## Why the variables are not extracted blindly
 *
 * `extract()` is what makes `$leads` available inside the view instead of
 * `$this->data['leads']`, and it is also how a view ends up overwriting the
 * framework's own `$path` mid-render. The reserved names below are refused
 * rather than silently dropped, because a view that thinks it set `$path` and
 * did not is much harder to debug than one that refuses to render.
 */
class ViewResponse implements ResponseInterface
{
    /**
     * Names a view may not use, because this method already holds them.
     *
     * @var list<string>
     */
    private const RESERVED = ['path', 'data', '__path', '__data'];

    private bool $sent = false;

    /**
     * @param string               $path   Absolute path to the view file.
     * @param array<string, mixed> $data   Variables made available to the view.
     * @param int                  $status HTTP status code to send.
     * @param array<string, string> $headers Extra headers, name => value.
     */
    public function __construct(
        private readonly string $path,
        private readonly array $data = [],
        private readonly int $status = 200,
        private readonly array $headers = []
    ) {
    }

    /**
     * Render the view and write it to the response.
     *
     * @throws HttpException When the view is missing, or raises while rendering.
     */
    public function send(): void
    {
        if ($this->sent) {
            throw HttpException::alreadySent(self::class);
        }

        $this->sent = true;

        // Rendered *first*, into a string, and only then echoed and given its
        // status. This is what makes the failure mode clean in both directions:
        // a view that throws has written nothing and committed no status, so the
        // caller's error handling still owns the response. Sending headers first
        // would leave a 200 already on the wire with no body behind it.
        //
        // It also means the body is fully in memory before anything is emitted,
        // so a view cannot half-write and then fail part-way through a loop.
        $body = $this->render();

        $this->sendHeaders();

        echo $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Render to a string without sending anything.
     *
     * Separated from `send()` so a view can be composed into a larger document,
     * embedded in a JSON payload, or asserted on in a test — all of which need
     * the output without the side effects.
     *
     * @throws HttpException When the view is missing, or raises while rendering.
     */
    public function render(): string
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw HttpException::viewNotFound($this->path);
        }

        $__path = $this->path;
        $__data = $this->data;

        foreach (self::RESERVED as $reserved) {
            if (array_key_exists($reserved, $__data)) {
                throw new HttpException(sprintf(
                    'View [%s] was given a [%s] variable, which is reserved by the renderer.',
                    $__path,
                    $reserved
                ));
            }
        }

        ob_start();

        try {
            // EXTR_SKIP is belt-and-braces alongside the reserved-name check: it
            // guarantees $__path and $__data survive even if the check above is
            // ever relaxed, because a view that clobbered $__path would then
            // include the wrong file on a nested render.
            extract($__data, EXTR_SKIP);

            require $__path;

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            // Cleaned before rethrowing, so the failed view's partial output is
            // discarded rather than left to be prepended to the error page.
            ob_end_clean();

            if ($e instanceof HttpException) {
                throw $e;
            }

            throw HttpException::viewFailed($__path, $e);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * Emit the status line and headers.
     *
     * Guarded on `headers_sent()` because a view rendered after the response has
     * started cannot set either, and calling the functions anyway produces a
     * warning — which the suite turns into a failure and a production log turns
     * into noise. The body is still sent; only the headers are skipped.
     */
    private function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        if ($this->status !== 200 && function_exists('status_header')) {
            status_header($this->status);
        }

        foreach ($this->headers as $name => $value) {
            header(sprintf('%s: %s', $name, $value), true);
        }
    }
}
