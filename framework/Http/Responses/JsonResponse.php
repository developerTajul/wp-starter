<?php
declare(strict_types=1);

namespace Framework\Http\Responses;

use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Exceptions\HttpException;

/**
 * A JSON response, for REST endpoints and headless clients.
 *
 * Wraps `wp_send_json()`, which does three things in one call: serializes the
 * payload, sets `Content-Type: application/json`, and `die()`s. All three are
 * wanted here, but the third is why this class is written the way it is — the
 * function never returns, so nothing after `send()` runs and a pipeline must
 * treat sending as terminal.
 *
 * `wp_send_json()` is preferred over building a `WP_REST_Response` because it
 * works outside REST dispatch too. A `WP_REST_Response` is only rendered by the
 * REST server; an admin-ajax handler or a form endpoint that returned one would
 * emit nothing at all.
 *
 * Sending is refused when `wp_send_json()` is unavailable rather than silently
 * echoing a JSON string through `echo`. `echo` on a JSON body without the
 * content type produces a response the client parses as HTML — a failure that
 * surfaces as a confusing parse error on the client rather than an error on the
 * server.
 */
final class JsonResponse implements ResponseInterface
{
    private bool $sent = false;

    /**
     * @param mixed                $data    Anything json_encode() accepts.
     * @param array<string, mixed> $options Options forwarded to wp_send_json():
     *                                      `status_code` (int), `flags` (int),
     *                                      or `headers` (array). Defaults to 200.
     */
    public function __construct(
        private readonly mixed $data = null,
        private readonly array $options = []
    ) {
    }

    /**
     * @throws HttpException When no JSON sender is available, or the response was
     *                       already sent.
     */
    public function send(): void
    {
        if ($this->sent) {
            throw HttpException::alreadySent(self::class);
        }

        if (!function_exists('wp_send_json')) {
            throw new HttpException(
                'wp_send_json() is not available; a JsonResponse can only be sent from within WordPress.'
            );
        }

        // Flagged before the call, not after: wp_send_json() dies, so the line
        // after it is unreachable and the flag would never be set. Its purpose is
        // to catch a second send() in the same process — a re-entrant middleware,
        // a response stored and re-sent — not to survive the die().
        $this->sent = true;

        wp_send_json($this->data, $this->status(), $this->options['flags'] ?? 0);
    }

    public function status(): int
    {
        return (int) ($this->options['status_code'] ?? 200);
    }

    /**
     * @return mixed
     */
    public function data(): mixed
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function withStatus(int $status): self
    {
        return $this->with(['status_code' => $status]);
    }

    /**
     * A copy of this response with header options added.
     *
     * Returns a new instance rather than mutating: a response is often built from
     * a shared constant or a value returned from a helper, and mutating one in
     * place would change it for every other holder.
     *
     * @param array<string, mixed> $options
     */
    public function with(array $options): self
    {
        return new self($this->data, array_replace($this->options, $options));
    }
}
