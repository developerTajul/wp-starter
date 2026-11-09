<?php
declare(strict_types=1);

namespace Framework\Http;

use Framework\Http\Exceptions\HttpException;

/**
 * An HTTP request, decoupled from PHP's superglobals.
 *
 * This is the **only** place in the framework allowed to read `$_GET`, `$_POST`,
 * `$_SERVER`, `$_COOKIE` or `$_FILES`, and the only place allowed to open
 * `php://input`. Everything downstream receives a Request object, which is what
 * makes a controller, a middleware or a service testable without a web server  - 
 * and what makes the headless case work at all.
 *
 * Construction is deliberately split from capture:
 *
 *   Request::capture()      reads the superglobals  -  used once, in production.
 *   new Request(...)        takes the parts explicitly  -  used everywhere else.
 *
 * A test builds the second form and never touches a superglobal; the production
 * entry point calls the first. A single constructor that read globals by default
 * would make every test that wanted a specific body mutate `php://input`'s
 * stand-in instead, which cannot be done.
 *
 * A JSON body is decoded and folded into the request's parameters, so
 * `input('email')` finds the value whether the client sent a form post or a
 * `{"email": ...}` document. The `Content-Type` header is checked rather than the
 * body sniffed, because the header states the client's intent, and a form body
 * that happens to be valid JSON is still a form body.
 *
 * Parameters merge `query` -> `body` -> `json`, so the later, more specific
 * source wins and `all()` reports the merged view. `query()` reads the raw query
 * source only. To build a request from a WordPress `WP_REST_Request` rather than
 * the superglobals, {@see fromRestRequest()} takes that request's already-resolved
 * parameter order instead.
 *
 * On a routed REST request a URL-matched parameter outranks a query parameter of
 * the same name: `GET /leads/999?id=1` gives `input('id') === '999'`. That is not
 * what `WP_REST_Request` does alone; core's order puts `GET` above `URL` and
 * unpinned core answers `'1'`. The reversal comes from the router calling
 * `set_param()` for every URL-matched name before the controller runs, and
 * `set_param()` overwrites the key in every bucket it appears in, so the URL
 * value has already replaced the query value inside the query bucket by the time
 * this class reads it. Against a real install the two paths then agree.
 */
class Request
{
    /**
     * The JSON body, decoded once. Null means "no JSON parameters"  -  either
     * there was no body, or it decoded to a scalar.
     *
     * @var array<int|string, mixed>|null
     */
    private ?array $json = null;

    /**
     * Cache for rawBody(). Separate from $json because reading the body and
     * decoding it are different things, and a non-JSON request still has one.
     */
    private ?string $rawBody = null;

    /**
     * @param array<string, mixed>      $query  The query string parameters.
     * @param array<string, mixed>      $body   Parsed request-body parameters.
     * @param array<string, mixed>      $server The server/environment parameters.
     * @param array<string, mixed>      $files  Uploaded files, in $_FILES shape.
     * @param array<string, string>     $cookies The request cookies, name => value.
     * @param string|null               $rawBody The undecoded request body, or null to
     *                                          read it lazily from php://input.
     * @param array<string, string>     $headers Lowercased header names, `-` separated.
     * @param bool                      $decoded Internal. True to skip decoding the raw
     *                                          body into the JSON bucket  -  used only by
     *                                          {@see withInput()}, which supplies
     *                                          parameters that are already resolved. A
     *                                          caller outside this class never passes it.
     */
    public function __construct(
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $server = [],
        private readonly array $files = [],
        private readonly array $cookies = [],
        ?string $rawBody = null,
        private readonly array $headers = [],
        bool $decoded = false
    ) {
        $this->rawBody = $rawBody;

        // Decoded in the constructor rather than lazily, because the result is a
        // readonly-ish cache that a lazy getter would have to make mutable  -  and
        // because a malformed JSON body is worth knowing about at the point the
        // request is built, not three frames later inside a controller. A broken
        // body does not throw here (see decodeJson()); isJson() simply reports
        // false, and input() falls back to the other sources.
        //
        // `$decoded` is the escape hatch for the one caller that has already
        // resolved its parameters  -  see withInput(). Without it a transformed copy
        // of a JSON request would re-decode the unchanged raw body and reinstate
        // the untransformed values on top of the transformed ones, which is a
        // silent no-op on exactly the requests a headless client sends.
        if (!$decoded && $this->isJson()) {
            $this->json = $this->decodeJson();
        }
    }


    /**
     * Build a Request from PHP's superglobals. The production entry point.
     *
     * The only method in the framework that reads a superglobal. Every value is
     * normalized to an array because a caller can filter a superglobal to a
     * non-array, and because `parse_str`-free code cannot otherwise be sure.
     */
    public static function capture(): self
    {
        return new self(
            query: self::superglobal($_GET),
            body: self::superglobal($_POST),
            server: self::superglobal($_SERVER),
            files: self::superglobal($_FILES),
            cookies: self::superglobal($_COOKIE),
            // php://input is read here rather than in the constructor so that a
            // hand-built Request never opens it. On a JSON POST this stream is
            // the *only* place the payload exists  -  PHP does not populate $_POST
            // for a non-form content type  -  so skipping it would make every
            // headless request arrive empty.
            rawBody: self::readInputStream(),
            headers: self::captureHeaders(self::superglobal($_SERVER))
        );
    }

    /**
     * Build a Request from a WordPress REST request.
     *
     * Used by the REST entry point, where WordPress has already resolved the
     * parameters and knows the URL-matched ones separately.
     *
     * The parameter split mirrors WordPress's own `get_parameter_order()`  -  JSON,
     * then body, then query, then URL.
     *
     * **The URL params are folded into the body layer, on top of it.** WordPress
     * ranks URL params last, so an unpinned `?id=999` outranks the `123` that
     * `posts/{id}` matched. `Routing::Router` corrects that by calling
     * `set_param()` per URL name, which overwrites the value *inside whichever
     * source already held the key*; this class cannot call `set_param()`, so it
     * reaches the same answer structurally  -  URL params go in the later,
     * winning layer.
     *
     * The query layer keeps everything else the client sent, including a
     * parameter that merely *shares a name* with a URL param. Dropping it here
     * would make `query('id')` return null for a value the client really did
     * send, and leave no way to reach it  -  a controller filtering by `?id=` while
     * the route also matches `{id}` would be reading a parameter it cannot see.
     * Where the names collide, `input()` reports the URL value, which is the
     * router's decision and not a reason to destroy the other one.
     *
     * A `WP_REST_Request` body is already decoded into its parameter buckets, and
     * the raw body is not always available, so `rawBody` is given as the raw body
     * when the request can supply one  -  which is what keeps `json()` and
     * `jsonOrFail()` consistent with `isJson()` instead of reporting an empty
     * document for a request that did carry one.
     */
    public static function fromRestRequest(object $request): self
    {
        $server = [];
        $headers = [];

        // Duck-typed rather than type-hinted against WP_REST_Request: this class
        // is loaded by the framework, which must stay usable without WordPress.
        if (method_exists($request, 'get_headers')) {
            foreach ((array) $request->get_headers() as $name => $value) {
                // WordPress canonicalizes header names by lowercasing them and
                // replacing `-` with `_`, so its array is keyed `content_type`,
                // not `content-type`. Translating back is what makes
                // `header('Content-Type')` work here  -  and getting the direction
                // wrong fails silently, because the lookup simply finds nothing
                // and every `isJson()` answers false.
                //
                // Each header is stored as a list of values; the client's intent
                // is the first one.
                $headers[strtolower(str_replace('_', '-', (string) $name))] = (string) (
                    is_array($value) ? reset($value) : $value
                );
            }
        }

        if (isset($headers['host'])) {
            $server['HTTP_HOST'] = $headers['host'];
        }

        $server['REQUEST_METHOD'] = method_exists($request, 'get_method')
            ? (string) $request->get_method()
            : 'GET';

        $urlParams = method_exists($request, 'get_url_params') ? (array) $request->get_url_params() : [];

        // The query layer is read directly rather than derived from get_params(),
        // which returns the merged view and would leave no way to tell a query
        // param from a body one.
        $query = method_exists($request, 'get_query_params')
            ? (array) $request->get_query_params()
            : array_diff_key(method_exists($request, 'get_params') ? (array) $request->get_params() : [], $urlParams);

        // get_body() is not always usable: for GET and for multipart it returns
        // an empty string, and a form body is in the POST bucket rather than a
        // raw stream. An empty string is the honest answer there  -  it decodes to
        // null, which is what json() should report for a request with no JSON.
        $rawBody = method_exists($request, 'get_body') ? (string) $request->get_body() : '';

        return new self(
            query: $query,
            body: $urlParams,
            server: $server,
            headers: $headers,
            rawBody: $rawBody
        );
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function superglobal(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Read php://input, tolerating a stream that cannot be opened.
     *
     * `php://input` is unreadable in some SAPIs and for `multipart/form-data`  - 
     * in both cases the payload is already in $_POST and nothing is lost. An
     * empty string is therefore the correct outcome, not an error.
     */
    private static function readInputStream(): string
    {
        $handle = @fopen('php://input', 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            // php://input is not seekable, so it is read once and cached. A
            // second read would return an empty string, which is the classic
            // "the JSON body vanished" bug in code that reads it twice.
            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
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

            // CONTENT_TYPE and CONTENT_LENGTH are the two the CGI spec does not
            // give an HTTP_ prefix, and they are the two that matter most here:
            // CONTENT_TYPE is what decides whether the body is JSON.
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


    /**
     * Every parameter this request carries, merged query -> body -> JSON.
     *
     * Merged with `array_replace` rather than `array_merge`, because a JSON
     * *list* decodes to integer keys: `array_merge` would renumber them onto the
     * end of the body's keys, while `array_replace` leaves each source's own
     * indices intact. For the object case the two behave identically.
     *
     * @return array<int|string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->query, array_replace($this->body, $this->json ?? []));
    }

    /**
     * A copy of this request with its merged parameters transformed.
     *
     * Middleware such as `TrimStrings`, `ConvertEmptyStringsToNull` and
     * `SanitizeInput` hand a cleaner request to the rest of the stack, and this
     * class is immutable and exposes no raw source buckets, so without this method
     * they cannot be written at all except by reaching into the constructor from
     * outside: that would mean re-reading the superglobals (losing the URL
     * parameters a routed request carries) or duplicating every constructor
     * argument at the call site.
     *
     * It returns a new instance rather than mutating, because the request a
     * middleware received may still be held by whoever sent it: the adapter's
     * `Request` binding, a log entry, an outer middleware wanting the original on
     * the way back out.
     *
     * Only the merged parameters are transformed. `$transform` is applied to
     * `all()` and the result replaces the body bucket; the query and JSON buckets
     * are emptied, because leaving the originals would mean `input()` still
     * reported untrimmed values, since the merged view takes the last source and a
     * stale `query` entry would reappear the moment nothing overwrote it. Method,
     * headers, cookies, files, server values and raw body are carried over
     * untouched: transport facts, not user input.
     *
     * The consequence for `post()` is worth naming: afterwards `post()` and
     * `input()` agree, both reading transformed values, whereas before they
     * differed for a JSON request. That is intended; the transform describes what
     * the application should see as input, and `post()` is not a back door around
     * it.
     *
     * The JSON bucket is carried, not re-decoded. A request with
     * `Content-Type: application/json` decodes its raw body into a JSON bucket in
     * the constructor, and that bucket wins the merge in {@see all()}. Building
     * the copy through the ordinary constructor would re-decode the unchanged raw
     * body and reinstate the untransformed values on top of the transformed ones,
     * so the transform would appear to do nothing on exactly the requests a
     * headless client sends.
     *
     * So the copy is built through {@see fromTransformedInput()}, which is the
     * constructor with the decode step suppressed. The raw body is still carried
     * unchanged, so `rawBody()` and `isJson()` keep reporting the wire format the
     * client actually sent; what changes is only which values `input()` resolves.
     *
     * @param callable(array<int|string, mixed>): array<int|string, mixed> $transform
     */
    public function withInput(callable $transform): self
    {
        return self::fromTransformedInput(
            $transform($this->all()),
            $this->server,
            $this->files,
            $this->cookies,
            $this->rawBody(),
            $this->headers
        );
    }

    /**
     * Build a copy whose parameters are already resolved, with no decode pass.
     *
     * Private and static because it exists for exactly one caller  - 
     * {@see withInput()}  -  and is the only construction path in this class that
     * skips the constructor's JSON decoding. Everything a caller reaches for
     * normally goes through the public constructor; keeping this narrow is what
     * stops "no decode" from becoming a second, differently-behaved entry point.
     *
     * The transformed parameters go in the **body** bucket with the query and
     * JSON buckets left empty, so `all()`  -  which merges query, then body, then
     * JSON  -  reports them and nothing else. The headers, server values, cookies,
     * files and raw body are carried across unchanged, so the copy still
     * describes the same wire request.
     *
     * @param array<int|string, mixed> $input
     * @param array<string, mixed>     $server
     * @param array<string, mixed>     $files
     * @param array<string, string>    $cookies
     * @param array<string, string>    $headers
     */
    private static function fromTransformedInput(
        array $input,
        array $server,
        array $files,
        array $cookies,
        string $rawBody,
        array $headers
    ): self {
        return new self(
            query: [],
            body: $input,
            server: $server,
            files: $files,
            cookies: $cookies,
            rawBody: $rawBody,
            headers: $headers,
            // The parameters are already resolved; re-decoding the raw body would
            // overwrite them with the client's untransformed originals.
            decoded: true
        );
    }

    /**
     * One parameter, whichever source it came from.
     *
     * `$default` is returned when the key is absent; a present key holding null
     * returns null, so "sent as null" and "not sent" stay distinguishable.
     *
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * One query-string parameter  -  the raw query source only.
     *
     * Distinct from `input()` on purpose: a query parameter is part of the
     * address the client asked for, while a body parameter is part of what they
     * said. A controller deciding what to fetch wants the former.
     *
     * @param mixed $default
     * @return mixed
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    /**
     * One parameter from the parsed body  -  the body source only.
     *
     * Does **not** see JSON parameters. That is deliberate rather than an
     * oversight: `post()` exists for code that specifically wants form-encoded
     * input, and the whole point of the JSON-aware request is that `input()` is
     * the method that does not care which encoding arrived. Code that wants
     * "whatever the body carried" calls `input()`; code that wants "the form
     * post" calls `post()` and gets nothing from a JSON client.
     *
     * @param mixed $default
     * @return mixed
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->body) ? $this->body[$key] : $default;
    }

    /**
     * The decoded JSON body, or null when there is none.
     *
     * @return array<int|string, mixed>|null
     */
    public function json(): ?array
    {
        return $this->json;
    }

    /**
     * The decoded JSON body, or a throw when the body is not decodable.
     *
     * The strict counterpart to `json()`: use it where a malformed body must be
     * reported to the client rather than silently treated as empty.
     *
     * @return array<int|string, mixed>
     *
     * @throws HttpException When the body is not valid JSON.
     */
    public function jsonOrFail(): array
    {
        $body = $this->rawBody();

        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw HttpException::malformedJson(json_last_error_msg());
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The undecoded request body.
     */
    public function rawBody(): string
    {
        return $this->rawBody ??= self::readInputStream();
    }

    /**
     * One server/environment value, e.g. `REMOTE_ADDR`.
     *
     * @param mixed $default
     * @return mixed
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->server) ? $this->server[$key] : $default;
    }

    /**
     * Uploaded files, in $_FILES shape.
     *
     * Returned as-is rather than normalized: $_FILES' parallel-arrays layout for
     * a multi-file field is awkward but universal, and every consumer of it  - 
     * `media_handle_upload()`, `wp_handle_upload()`  -  already expects that shape.
     * Normalizing here would mean un-normalizing at the call site.
     *
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }


    /**
     * The uppercase HTTP method, defaulting to GET.
     *
     * A `method` override is honoured, because a form can only send GET or POST
     * and HTML has no other way to express PUT or DELETE.
     */
    public function method(): string
    {
        $override = $this->body['_method'] ?? $this->query['_method'] ?? null;

        if (is_string($override) && trim($override) !== '') {
            return strtoupper(trim($override));
        }

        $method = $this->server['REQUEST_METHOD'] ?? 'GET';

        return is_string($method) && $method !== '' ? strtoupper($method) : 'GET';
    }

    /**
     * Whether the request used the given method, case-insensitively.
     */
    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    /**
     * Whether the client asked for a JSON response.
     *
     * True when it declared `Accept: application/json`  -  or when it sent a JSON
     * body, since a client that speaks JSON into an endpoint can only be
     * answered in kind.
     */
    public function expectsJson(): bool
    {
        if ($this->isJson()) {
            return true;
        }

        $accept = $this->header('accept');

        if ($accept === null) {
            return false;
        }

        // Matched rather than compared, because Accept is a list with quality
        // values  -  `application/json;q=0.9, text/html` is a JSON request.
        return stripos($accept, 'application/json') !== false
            || stripos($accept, '+json') !== false;
    }

    /**
     * Whether the request body is declared as JSON.
     *
     * Decided by `Content-Type`, not by sniffing: a client that sends form data
     * has told you what it sent, and guessing from the bytes would make
     * `Content-Type` advisory. The charset parameter and a vendor media type
     * (`application/vnd.api+json`) are both accepted.
     */
    public function isJson(): bool
    {
        $contentType = $this->header('content-type');

        if ($contentType === null) {
            return false;
        }

        $mediaType = strtolower(trim(explode(';', $contentType)[0]));

        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    /**
     * Whether the request was made over HTTPS.
     *
     * Proxies are believed deliberately: this decides a redirect scheme, not a
     * security boundary, and a load balancer terminating TLS is the normal
     * deployment. A response that must not be downgraded should say so with
     * HSTS rather than by second-guessing the proxy here.
     */
    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? null;

        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return $this->header('x-forwarded-proto') === 'https';
    }

    /**
     * Whether this is an XMLHttpRequest, by the de-facto header.
     */
    public function isAjax(): bool
    {
        return strtolower((string) $this->header('x-requested-with')) === 'xmlhttprequest';
    }

    /**
     * A request header by lowercased name, e.g. `content-type`.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The request URI, as sent  -  path and query string, no scheme or host.
     */
    public function uri(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';

        return is_string($uri) && $uri !== '' ? $uri : '/';
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }


    /**
     * Decode the body, returning null rather than throwing on malformed input.
     *
     * The constructor uses this so that a broken body yields a request with no
     * JSON parameters instead of an exception escaping construction  -  a request
     * that cannot be built has nothing to report the failure through. Callers
     * that need the loud version call `jsonOrFail()`, which re-decodes and
     * throws with the real `json_last_error_msg()`.
     *
     * A JSON document that is not an object  -  `"hello"`, `42`, `null`  -  decodes
     * to a non-array and is treated as no JSON parameters, because a scalar
     * cannot be addressed by `input($key)` at all.
     *
     * A **list** is a different case and is returned as one. `[1,2]` is a real
     * JSON document a client may plausibly send  -  a bulk operation, an array of
     * ids  -  and discarding it here would make `all()` and `json()` disagree with
     * `jsonOrFail()`, which returns it. Parameters are then addressed by index,
     * which is the best that can be done with a body that has no keys.
     *
     * @return array<int|string, mixed>|null
     */
    private function decodeJson(): ?array
    {
        $body = $this->rawBody();

        if (trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
