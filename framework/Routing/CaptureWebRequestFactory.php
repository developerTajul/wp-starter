<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Http\Request;

/**
 * The production request factory: captures from the superglobals.
 *
 * Why this class also reads the superglobals
 *
 * `Framework\Http\Request` documents itself as "the **only** place in the
 * framework allowed to read `$_GET`, `$_POST`, `$_SERVER`", and this class is
 * the deliberate exception - with the reason written down rather than assumed.
 *
 * A matched route placeholder has to outrank a body parameter of the same name:
 * a form that posts `id=999` to `/leads/123` must not let the client choose
 * which record the controller acts on. `Request` is immutable and exposes no
 * setter and no whole-array accessor - it offers `input($key)`, `query($key)`
 * and `post($key)`, one key at a time - so a request carrying an overlaid
 * parameter cannot be built from a captured one through its public surface.
 *
 * The two alternatives were both worse. Widening `Request`'s public API with
 * whole-array getters would add a Phase 5 need to a Phase 4 type every consumer
 * sees, and would hand callers a way to read the raw bags that the keyed methods
 * deliberately do not offer. Rebuilding the request from `Request::capture()`
 * and mutating it is impossible, because it is immutable - which is the property
 * worth keeping.
 *
 * So the overlay is built here, at the seam, where the values are read once and
 * handed straight to the constructor. Nothing downstream reads a superglobal.
 *
 * The overlay is layered, not merged
 *
 * URL parameters are folded over the **body** layer, which is the later of the
 * sources `input()` consults - the same structural move
 * `Request::fromRestRequest()` makes, for the same reason. They are also placed
 * over the query layer so that `query('id')` and `input('id')` agree about a
 * request where a placeholder and a query parameter share a name; the REST path
 * makes the same choice, and a request whose two accessors disagreed would be a
 * trap for whoever read the other one.
 */
final class CaptureWebRequestFactory implements WebRequestFactory
{
    public function make(array $urlParams = []): Request
    {
        if ($urlParams === []) {
            return Request::capture();
        }

        // Read into locals rather than referencing the superglobals repeatedly,
        // so a filter that fires mid-construction cannot make the two reads
        // disagree about the same request.
        $query = self::bag($_GET);
        $body = self::bag($_POST);

        return new Request(
            query: array_replace($query, $urlParams),
            body: array_replace($body, $urlParams),
            server: self::bag($_SERVER),
            files: self::bag($_FILES),
            cookies: self::bag($_COOKIE),
            // Null rather than a read of php://input: passing null is what makes
            // the constructed Request read the stream lazily, through the one
            // method the framework sanctions for it. Reading it here would open
            // the stream a second time in the same process and, because it is not
            // seekable, return an empty body.
            rawBody: null,
            headers: self::captureHeaders(self::bag($_SERVER))
        );
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function bag(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * The request headers, from `$_SERVER`.
     *
     * A copy of `Request::captureHeaders()` rather than a call to it, because
     * that method is private and the alternative - making it public - would
     * expose a Phase 4 internal for this one caller. The duplication is
     * deliberate and narrow; the mapping it encodes is the CGI spec's, not a
     * decision either class gets to make differently.
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function captureHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = $key;
            } else {
                continue;
            }

            $headers[strtolower(str_replace('_', '-', $name))] = (string) $value;
        }

        return $headers;
    }
}
