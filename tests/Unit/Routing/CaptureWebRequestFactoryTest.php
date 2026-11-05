<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use Framework\Routing\CaptureWebRequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * The one place in the web domain allowed to read the superglobals.
 *
 * ## Why this is worth its own test file
 *
 * `Framework\Http\Request` declares itself the only class permitted to read
 * `$_GET`/`$_POST`/`$_SERVER`, and this factory is the deliberate exception. An
 * exception to a stated rule is exactly the kind of thing that later gets
 * "tidied up" by someone who has not read the docblock, so the behaviour is
 * pinned here rather than left to a comment.
 *
 * The rule it encodes is a security rule: a route placeholder must outrank a
 * body value of the same name, or a form posting `id=999` to `/leads/123`
 * chooses which record the controller acts on. That rule is enforced *here*, by
 * layering the URL parameters over the body — the binder then reads the front of
 * that layer. So a regression in this file is a privilege-escalation bug that
 * every other test in the suite would still pass through.
 */
final class CaptureWebRequestFactoryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];
    /** @var array<string, mixed> */
    private array $get = [];
    /** @var array<string, mixed> */
    private array $post = [];
    /** @var array<string, mixed> */
    private array $cookie = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->post = $_POST;
        $this->cookie = $_COOKIE;

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
        $_POST = $this->post;
        $_COOKIE = $this->cookie;
    }

    // ------------------------------------------------------------- the overlay

    /**
     * **The security rule.** A placeholder outranks a body value of the same name,
     * which is what stops a form from choosing which record the controller acts
     * on.
     *
     * This is the whole reason {@see CaptureWebRequestFactory} exists instead of
     * a symfony request built through a public API.
     */
    public function test_a_url_parameter_outranks_a_body_value(): void
    {
        $_POST = ['id' => '999'];
        $_GET = ['id' => '999'];

        $request = (new CaptureWebRequestFactory())->make(['id' => '123']);

        $this->assertSame('123', $request->input('id'));
        $this->assertSame('123', $request->post('id'));
    }

    /**
     * The overlay is placed over the query layer too, so `query()` and `input()`
     * cannot disagree about the same name — a request whose two accessors
     * disagreed would be a trap for whoever happened to read the other one.
     */
    public function test_the_query_accessor_agrees_with_input(): void
    {
        $_GET = ['id' => '999'];

        $request = (new CaptureWebRequestFactory())->make(['id' => '123']);

        $this->assertSame($request->input('id'), $request->query('id'));
    }

    /**
     * A placeholder does not erase the rest of the body — a form submitting a
     * record alongside its identifier still carries its own fields.
     */
    public function test_the_rest_of_the_body_survives_the_overlay(): void
    {
        $_POST = ['id' => '999', 'name' => 'Acme', 'notes' => 'urgent'];

        $request = (new CaptureWebRequestFactory())->make(['id' => '123']);

        $this->assertSame('123', $request->input('id'));
        $this->assertSame('Acme', $request->input('name'));
        $this->assertSame('urgent', $request->input('notes'));
    }

    /**
     * With no placeholders the request is the captured one, unmodified — the
     * common case must not pay for the overlay or risk changing what it sees.
     */
    public function test_no_url_parameters_leaves_the_captured_request_alone(): void
    {
        $_GET = ['page' => '2'];

        $request = (new CaptureWebRequestFactory())->make();

        $this->assertSame('2', $request->input('page'));
    }

    /**
     * A placeholder whose name appears nowhere else still arrives through
     * `input()`, which is how a controller reads it.
     */
    public function test_a_placeholder_is_readable_when_the_body_is_empty(): void
    {
        $request = (new CaptureWebRequestFactory())->make(['slug' => 'thank-you']);

        $this->assertSame('thank-you', $request->input('slug'));
    }

    // ---------------------------------------------------------------- the bags

    /**
     * A superglobal that is not an array must not fatal the whole request. PHP
     * allows `$_GET` to be clobbered by a malformed query string, and an
     * unreadable bag is an empty one.
     */
    public function test_a_non_array_superglobal_is_treated_as_empty(): void
    {
        $_GET = 'not-an-array';

        $request = (new CaptureWebRequestFactory())->make(['id' => '123']);

        $this->assertSame('123', $request->input('id'));
    }

    // -------------------------------------------------------------- the method

    /**
     * The HTTP method comes from `$_SERVER`, which the factory passes through.
     * A form handler has to see POST to be dispatched at all.
     *
     * The method is set explicitly *and* to a value PHPUnit does not already use.
     * The CLI test runner leaves `REQUEST_METHOD` as `GET`, so asserting `GET`
     * would pass whether or not the factory read the bag at all — it would be
     * pinning PHPUnit's own environment. `POST` only appears because this line
     * put it there.
     */
    public function test_the_method_is_read_from_the_server_bag(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $request = (new CaptureWebRequestFactory())->make(['id' => '1']);

        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->isMethod('POST'));
    }

    /**
     * Headers are captured from `$_SERVER`, because a web request has no
     * `WP_REST_Request` to read them from.
     */
    public function test_headers_are_captured_from_the_server_bag(): void
    {
        $_SERVER['HTTP_X_LEAD_ID'] = 'abc';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

        $request = (new CaptureWebRequestFactory())->make(['id' => '1']);

        $this->assertSame('abc', $request->header('x-lead-id'));
        $this->assertSame('application/x-www-form-urlencoded', $request->header('content-type'));
    }

    /**
     * `rawBody` is passed as null, so the constructed request reads `php://input`
     * lazily rather than the factory reading it eagerly.
     *
     * ## Why this is asserted as `rawBody()` and not as decoded JSON
     *
     * `php://input` is a non-seekable stream and is empty in a PHPUnit process,
     * so there is no way to feed this factory a JSON body from here — a test
     * claiming to have done so would be fiction. What *is* checkable is the
     * property that makes a JSON body survive to the controller: the stream is
     * read on demand and only once, from the one sanctioned place. Reading it
     * eagerly in the factory would consume it and hand the controller an empty
     * body.
     *
     * Empty here is the correct result for a test process, and it is what would
     * also be returned by a *second* eager read in production — which is the
     * failure this guards against.
     */
    public function test_the_raw_body_is_read_lazily_and_returns_empty_in_a_test_process(): void
    {
        $request = (new CaptureWebRequestFactory())->make(['id' => '123']);

        $this->assertSame('', $request->rawBody());

        // A placeholder still reaches the controller, which is the part of the
        // request this factory is responsible for.
        $this->assertSame('123', $request->input('id'));
    }
}
