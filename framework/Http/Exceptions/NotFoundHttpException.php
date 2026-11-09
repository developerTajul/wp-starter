<?php
declare(strict_types=1);

namespace Framework\Http\Exceptions;

/**
 * Nothing exists at the requested address.
 *
 * A named subclass rather than `new HttpException($m, 404)`, for the reason every
 * status worth branching on gets one: the *catch* is the thing that matters. A
 * resolver that wants "did this fail because there is no such route" asks
 * `catch (NotFoundHttpException)`, and that is checkable; `catch (HttpException)`
 * followed by `if ($e->statusCode() === 404)` is a branch every caller must
 * remember to write, and one that is invisible at the throw site.
 *
 * Why 404 and not 403 for a hidden resource
 *
 * The lead services explain the same choice from the other side: an unauthorized
 * edit and a missing row deliberately produce the same answer, because a
 * distinguishing 403 confirms the row exists and turns a refused request into an
 * enumeration oracle. A route that wants that behaviour
 * raises this for both cases, and the client cannot tell them apart - which is
 * the point.
 */
final class NotFoundHttpException extends HttpException
{
    /**
     * @param string                $message
     * @param array<string, string> $headers
     */
    public function __construct(string $message = 'Not found.', array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 404, $headers, 0, $previous);
    }
}
