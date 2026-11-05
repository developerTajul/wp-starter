<?php
declare(strict_types=1);

namespace Framework\Http\Contracts;

use Framework\Http\Exceptions\HttpException;

/**
 * Something that can be sent to the client.
 *
 * The contract is deliberately one method. Everything a response needs to decide
 * — status, headers, body, whether it terminates the request — it already knows
 * by the time `send()` is called; nothing about the request is passed back in,
 * because a response that re-reads the request it answered is a response whose
 * behaviour cannot be predicted from its own state.
 *
 * `send()` is terminal. Some implementations (`JsonResponse`) hand off to a
 * WordPress function that ends the request; others (`ViewResponse`) echo and
 * return. A pipeline treats both the same way — it stops after sending — so a
 * caller must not assume control returns.
 */
interface ResponseInterface
{
    /**
     * Emit this response.
     *
     * @throws HttpException When the response cannot be sent, or was sent twice.
     */
    public function send(): void;

    /**
     * The HTTP status code this response will carry.
     *
     * Exposed so middleware can branch on it on the way out — logging a failure,
     * adding a header to a success — without consuming the response. It is also
     * what makes a response assertable in a test that does not want to send it.
     */
    public function status(): int;
}
