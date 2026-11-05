<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use Framework\Http\Exceptions\HttpException;
use Framework\Http\Request;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * The Request object, exercised through its explicit constructor.
 *
 * Nothing here touches a superglobal: `capture()` is the only method that does,
 * and it is covered by {@see RequestCaptureTest}, which runs each case in its own
 * process because the superglobals it reads are process-global.
 */
final class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    // ------------------------------------------------------------- JSON parsing

    public function test_a_json_body_is_decoded_and_readable_like_form_data(): void
    {
        $request = $this->jsonRequest('{"email":"a@example.test","name":"Ada"}');

        $this->assertTrue($request->isJson());
        $this->assertSame('a@example.test', $request->input('email'));
        $this->assertSame('Ada', $request->input('name'));
    }

    public function test_a_json_body_is_merged_into_all(): void
    {
        $request = new Request(
            query: ['page' => '2'],
            rawBody: '{"email":"a@example.test"}',
            headers: ['content-type' => 'application/json']
        );

        $this->assertSame(
            ['page' => '2', 'email' => 'a@example.test'],
            $request->all()
        );
    }

    public function test_the_json_source_wins_over_the_body_source(): void
    {
        // A request cannot normally carry both, but the precedence has to be
        // defined anyway — the alternative is a merge whose result depends on
        // the order a caller happened to pass arguments in.
        $request = new Request(
            body: ['email' => 'from-body@example.test'],
            rawBody: '{"email":"from-json@example.test"}',
            headers: ['content-type' => 'application/json']
        );

        $this->assertSame('from-json@example.test', $request->input('email'));
    }

    public function test_query_is_readable_on_its_own_and_keeps_its_own_precedence(): void
    {
        $request = new Request(
            query: ['page' => '2', 'email' => 'from-query@example.test'],
            rawBody: '{"email":"from-json@example.test"}',
            headers: ['content-type' => 'application/json']
        );

        $this->assertSame('2', $request->query('page'));

        // post() is the body source only, so a query parameter is not visible
        // through it — and, for the same reason, neither is a JSON one.
        $this->assertSame('absent', $request->post('email', 'absent'));
        $this->assertSame('absent', $request->post('page', 'absent'));

        // input() sees the JSON layer on top, which is the documented order.
        $this->assertSame('from-json@example.test', $request->input('email'));
    }

    public function test_a_json_content_type_with_a_charset_is_recognised(): void
    {
        $request = new Request(
            rawBody: '{"ok":true}',
            headers: ['content-type' => 'application/json; charset=utf-8']
        );

        $this->assertTrue($request->isJson());
        $this->assertTrue($request->input('ok'));
    }

    public function test_a_vendor_json_media_type_is_recognised(): void
    {
        $request = new Request(
            rawBody: '{"ok":true}',
            headers: ['content-type' => 'application/vnd.api+json']
        );

        $this->assertTrue($request->isJson());
        $this->assertTrue($request->input('ok'));
    }

    public function test_a_form_body_that_happens_to_be_json_is_not_parsed(): void
    {
        // Content-Type is what the client declares, not what the bytes look like.
        // Sniffing instead would make the header advisory and turn a form field
        // containing a JSON blob into request parameters.
        $request = new Request(
            body: ['payload' => '{"a":1}'],
            rawBody: '{"a":1}',
            headers: ['content-type' => 'application/x-www-form-urlencoded']
        );

        $this->assertFalse($request->isJson());
        $this->assertNull($request->input('a'));
        $this->assertSame('{"a":1}', $request->input('payload'));
    }

    public function test_a_malformed_json_body_does_not_throw_on_construction(): void
    {
        // A request that cannot be built has no way to report the failure, so a
        // broken body degrades to "no parameters" and the loud version is opt-in.
        $request = new Request(
            rawBody: '{"email":',
            headers: ['content-type' => 'application/json']
        );

        $this->assertNull($request->json());
        $this->assertNull($request->input('email'));
    }

    public function test_json_or_fail_names_the_decoding_error(): void
    {
        $request = new Request(
            rawBody: '{"email":',
            headers: ['content-type' => 'application/json']
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('could not be decoded');
        $request->jsonOrFail();
    }

    public function test_json_or_fail_accepts_an_empty_body_as_no_parameters(): void
    {
        // A POST with no body and a JSON content type is ordinary — a DELETE
        // with no payload, a client that forgot the body. It is not malformed.
        $request = new Request(rawBody: '', headers: ['content-type' => 'application/json']);

        $this->assertSame([], $request->jsonOrFail());
    }

    public function test_a_scalar_json_document_yields_no_parameters(): void
    {
        // Valid JSON, but a scalar cannot be addressed by key at all.
        foreach (['"hello"', '42', 'null', 'true'] as $body) {
            $request = new Request(
                rawBody: $body,
                headers: ['content-type' => 'application/json']
            );

            $this->assertTrue($request->isJson());
            $this->assertNull($request->json(), "[{$body}] should decode to no parameters");
        }
    }

    public function test_a_json_list_is_kept_rather_than_discarded(): void
    {
        // A list is a real document a client may send — bulk ids, a batch — and
        // throwing it away here would make all() and json() disagree with
        // jsonOrFail(), which returns it.
        $request = new Request(
            rawBody: '[1,2]',
            headers: ['content-type' => 'application/json']
        );

        $this->assertSame([1, 2], $request->json());
        $this->assertSame([1, 2], $request->all());
        $this->assertSame([1, 2], $request->jsonOrFail());
    }

    // -------------------------------------------------------------------- input

    public function test_input_returns_the_default_for_an_absent_key(): void
    {
        $request = new Request(query: ['page' => '2']);

        $this->assertSame('fallback', $request->input('missing', 'fallback'));
        $this->assertNull($request->input('missing'));
    }

    public function test_a_present_null_is_not_the_same_as_an_absent_key(): void
    {
        $request = new Request(query: ['filter' => null]);

        $this->assertNull($request->input('filter', 'default'));
    }

    // ------------------------------------------------------------------- method

    public function test_the_method_comes_from_the_server_parameters(): void
    {
        $request = new Request(server: ['REQUEST_METHOD' => 'post']);

        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->isMethod('post'));
        $this->assertFalse($request->isMethod('GET'));
    }

    public function test_the_method_defaults_to_get_when_unset(): void
    {
        $this->assertSame('GET', (new Request())->method());
    }

    public function test_a_method_override_is_honoured(): void
    {
        // A form can only send GET or POST, so PUT and DELETE have to be
        // expressible some other way; _method is the convention.
        $request = new Request(
            body: ['_method' => 'delete'],
            server: ['REQUEST_METHOD' => 'POST']
        );

        $this->assertSame('DELETE', $request->method());
        $this->assertTrue($request->isMethod('delete'));
    }

    // -------------------------------------------------------------- content type

    public function test_expects_json_follows_the_accept_header(): void
    {
        $request = new Request(headers: ['accept' => 'application/json']);

        $this->assertTrue($request->expectsJson());
    }

    public function test_expects_json_reads_a_quality_valued_accept_header(): void
    {
        // Accept is a list with weights, not a single value — comparing it to
        // 'application/json' would report false for a JSON client.
        $request = new Request(headers: ['accept' => 'application/json;q=0.9, text/html']);

        $this->assertTrue($request->expectsJson());
    }

    public function test_expects_json_is_true_when_the_body_is_json(): void
    {
        $request = new Request(
            rawBody: '{"a":1}',
            headers: ['content-type' => 'application/json', 'accept' => 'text/html']
        );

        $this->assertTrue($request->expectsJson());
    }

    public function test_expects_json_is_false_for_a_plain_browser_request(): void
    {
        $request = new Request(headers: ['accept' => 'text/html,application/xhtml+xml']);

        $this->assertFalse($request->expectsJson());
    }

    public function test_headers_are_case_insensitive(): void
    {
        $request = new Request(headers: ['content-type' => 'application/json']);

        $this->assertSame('application/json', $request->header('Content-Type'));
        $this->assertSame('application/json', $request->header('CONTENT-TYPE'));
    }

    public function test_is_secure_follows_https_and_the_proxy_header(): void
    {
        $this->assertTrue((new Request(server: ['HTTPS' => 'on']))->isSecure());
        $this->assertFalse((new Request(server: ['HTTPS' => 'off']))->isSecure());
        $this->assertFalse((new Request())->isSecure());
        $this->assertTrue((new Request(headers: ['x-forwarded-proto' => 'https']))->isSecure());
    }

    public function test_is_ajax_follows_the_requested_with_header(): void
    {
        $this->assertTrue((new Request(headers: ['x-requested-with' => 'XMLHttpRequest']))->isAjax());
        $this->assertFalse((new Request())->isAjax());
    }

    // -------------------------------------------------- construction from REST

    public function test_from_rest_request_folds_url_parameters_above_the_body(): void
    {
        // The precedence that matters: WordPress ranks a query parameter above a
        // URL-matched one, so a controller reading `id` would act on the value
        // the client chose rather than the one the route matched.
        $rest = new \WP_REST_Request();
        $rest->set_url_params(['id' => '123']);
        $rest->set_params(['id' => '999']);

        $request = Request::fromRestRequest($rest);

        $this->assertSame('123', $request->input('id'));
    }

    public function test_from_rest_request_carries_method_and_headers(): void
    {
        $rest = new \WP_REST_Request();
        $rest->set_method('POST');
        $rest->set_header('content-type', 'application/json');
        $rest->set_header('accept', 'application/json');

        $request = Request::fromRestRequest($rest);

        $this->assertSame('POST', $request->method());

        // The names are normalized to the hyphenated, lowercased form Request
        // uses internally, so a caller never has to know the REST spelling —
        // WordPress stores Content-Type as the CONTENT_TYPE key of the array
        // get_headers() returns.
        $this->assertSame('application/json', $request->header('Content-Type'));
        $this->assertTrue($request->expectsJson());
    }

    public function test_from_rest_request_keeps_non_url_parameters_readable(): void
    {
        $rest = new \WP_REST_Request();
        $rest->set_url_params(['id' => '123']);
        $rest->set_query_params(['filter' => 'recent']);
        $rest->set_params(['id' => '123']);

        $request = Request::fromRestRequest($rest);

        $this->assertSame('recent', $request->input('filter'));
        $this->assertSame('recent', $request->query('filter'));
    }

    /**
     * A query parameter that merely *shares a name* with a URL parameter must
     * survive.
     *
     * `input()` reports the URL value, which is the router's decision. But the
     * client did send `?id=999`, and a controller filtering on it needs to reach
     * it — an earlier version rebuilt the query layer with `array_diff_key()`,
     * which deleted the key outright and made `query('id')` return null for a
     * value the client really sent.
     */
    public function test_a_query_parameter_is_not_destroyed_by_a_url_parameter_of_the_same_name(): void
    {
        $rest = new \WP_REST_Request();
        $rest->set_url_params(['id' => '123']);
        $rest->set_query_params(['id' => '999', 'filter' => 'recent']);
        $rest->set_params(['id' => '123']);

        $request = Request::fromRestRequest($rest);

        // The routed value wins for input(), as the router intends...
        $this->assertSame('123', $request->input('id'));

        // ...but the client's own value is still reachable.
        $this->assertSame('999', $request->query('id'));
        $this->assertSame('recent', $request->query('filter'));
    }

    /**
     * A REST request that carried a JSON body must not claim `isJson()` while
     * reporting an empty document.
     *
     * The raw body used to be hard-coded to `''`, so `isJson()` was true (the
     * Content-Type header was carried) while `json()` was null and `jsonOrFail()`
     * returned `[]`. A controller branching on `isJson()` then read nothing.
     */
    public function test_from_rest_request_keeps_the_json_body_readable(): void
    {
        $rest = new \WP_REST_Request();
        $rest->set_header('content-type', 'application/json');
        $rest->set_body('{"email":"a@b.test","tags":[1,2]}');

        $request = Request::fromRestRequest($rest);

        $this->assertTrue($request->isJson());
        $this->assertSame(['email' => 'a@b.test', 'tags' => [1, 2]], $request->json());
        $this->assertSame(['email' => 'a@b.test', 'tags' => [1, 2]], $request->jsonOrFail());
        $this->assertSame('a@b.test', $request->input('email'));
    }

    /**
     * A REST request with no body reports no JSON, rather than an empty document
     * that `jsonOrFail()` would hand back as if it were data.
     */
    public function test_from_rest_request_without_a_body_has_no_json(): void
    {
        $rest = new \WP_REST_Request();
        $rest->set_method('GET');
        $rest->set_header('content-type', 'application/json');

        $request = Request::fromRestRequest($rest);

        $this->assertTrue($request->isJson());
        $this->assertNull($request->json());
    }

    /**
     * @return Request
     */
    private function jsonRequest(string $body): Request
    {
        return new Request(
            rawBody: $body,
            headers: ['content-type' => 'application/json']
        );
    }
}
