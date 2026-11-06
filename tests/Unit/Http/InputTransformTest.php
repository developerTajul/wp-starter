<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Middleware\ConvertEmptyStringsToNull;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Middleware\SanitizeInput;
use Framework\Http\Middleware\TrimStrings;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * The input-transforming middleware, and the `Request::withInput()` seam they
 * need.
 *
 * ## Why the assertions are made on the request `$next` received
 *
 * These middleware do not answer anything — they hand a *different request*
 * onwards. Asserting on their return value would assert nothing. So every test
 * captures the `Request` that reached the destination and asserts on its input,
 * which is the only observable effect they have.
 *
 * That also pins the property the design depends on: the transformed copy is
 * what travels down the stack, and the original is untouched for whoever still
 * holds it.
 */
final class InputTransformTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->container = new Container();
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Container::class, $this->container);
    }

    // ------------------------------------------------------------ withInput()

    public function test_with_input_transforms_the_merged_parameters(): void
    {
        $request = new Request(query: ['q' => ' a '], body: ['b' => ' b ']);

        $result = $request->withInput(static fn (array $input): array => array_map('trim', $input));

        $this->assertSame('a', $result->input('q'));
        $this->assertSame('b', $result->input('b'));
    }

    /**
     * The original is untouched. A middleware holds the request it was given, and
     * an outer layer wants the real one on the way back out.
     */
    public function test_with_input_leaves_the_original_alone(): void
    {
        $request = new Request(body: ['email' => '  ada@example.test  ']);

        $request->withInput(static fn (array $input): array => array_map('trim', $input));

        $this->assertSame('  ada@example.test  ', $request->input('email'));
    }

    /**
     * `input()` must report the transformed value for a request that carried
     * JSON. If the JSON bucket were left in place it would win the merge and the
     * untransformed value would reappear — the exact failure that makes this
     * method's "empty the other buckets" behaviour load-bearing.
     */
    public function test_a_json_body_does_not_reappear_after_transformation(): void
    {
        $request = new Request(
            rawBody: '{"email":"  ada@example.test  "}',
            headers: ['content-type' => 'application/json']
        );

        $this->assertSame('  ada@example.test  ', $request->input('email'));

        $result = $request->withInput(static fn (array $input): array => array_map('trim', $input));

        $this->assertSame('ada@example.test', $result->input('email'));
    }

    /**
     * Transport facts are carried over: a transform describes what the
     * *application* should see as input, not a new request.
     */
    public function test_with_input_preserves_the_method_headers_and_body(): void
    {
        $request = new Request(
            body: ['a' => ' x '],
            server: ['REQUEST_METHOD' => 'POST'],
            cookies: ['session' => 'abc'],
            rawBody: 'a=%20x%20',
            headers: ['accept' => 'application/json']
        );

        $result = $request->withInput(static fn (array $input): array => array_map('trim', $input));

        $this->assertSame('POST', $result->method());
        $this->assertSame('application/json', $result->header('accept'));
        $this->assertSame('abc', $result->cookie('session'));
        $this->assertSame('a=%20x%20', $result->rawBody());
    }

    // ----------------------------------------------------------- TrimStrings

    public function test_trim_strings_trims_leading_and_trailing_whitespace(): void
    {
        $request = $this->through(new TrimStrings(), new Request(body: ['name' => "  Ada Lovelace\t"]));

        $this->assertSame('Ada Lovelace', $request->input('name'));
    }

    /**
     * Interior whitespace is a value the user typed — collapsing it is a
     * sanitization decision, not a trimming one, and belongs elsewhere.
     */
    public function test_trim_strings_leaves_interior_whitespace_alone(): void
    {
        $request = $this->through(new TrimStrings(), new Request(body: ['name' => 'Ada  Lovelace']));

        $this->assertSame('Ada  Lovelace', $request->input('name'));
    }

    /**
     * A multi-byte non-breaking space is *not* trimmed. Stripping it would mean
     * multibyte handling here, which is a different job — the boundary is stated
     * in the class docblock and pinned here so it cannot drift silently.
     */
    public function test_trim_strings_does_not_touch_a_non_breaking_space(): void
    {
        $nbsp = "\u{00A0}ada\u{00A0}";

        $request = $this->through(new TrimStrings(), new Request(body: ['name' => $nbsp]));

        $this->assertSame($nbsp, $request->input('name'));
    }

    public function test_trim_strings_walks_nested_arrays(): void
    {
        $request = $this->through(
            new TrimStrings(),
            new Request(body: ['lead' => ['name' => ' Ada ', 'email' => ' a@b.test ']])
        );

        $this->assertSame(
            ['name' => 'Ada', 'email' => 'a@b.test'],
            $request->input('lead')
        );
    }

    /**
     * A number from a JSON body is not a string, and coercing it to one to
     * "clean" it would turn `42` into `'42'` — a bug in every consumer that then
     * compares it.
     */
    public function test_trim_strings_leaves_non_string_values_alone(): void
    {
        $request = $this->through(
            new TrimStrings(),
            new Request(body: ['count' => 42, 'active' => true, 'nothing' => null])
        );

        $this->assertSame(42, $request->input('count'));
        $this->assertTrue($request->input('active'));
        $this->assertNull($request->input('nothing'));
    }

    // -------------------------------------------- ConvertEmptyStringsToNull

    public function test_an_empty_string_becomes_null(): void
    {
        $request = $this->through(
            new ConvertEmptyStringsToNull(),
            new Request(body: ['phone' => ''])
        );

        $this->assertNull($request->input('phone'));
    }

    /**
     * `'0'` is a legitimate value — a quantity, a price, a selection — and PHP's
     * falsy coercions would happily eat it.
     */
    public function test_a_zero_string_is_not_null(): void
    {
        $request = $this->through(
            new ConvertEmptyStringsToNull(),
            new Request(body: ['quantity' => '0'])
        );

        $this->assertSame('0', $request->input('quantity'));
    }

    public function test_whitespace_only_becomes_null_once_trimmed_first(): void
    {
        // Trim then convert, which is the order routes/web.php declares.
        $request = $this->throughMany(
            [new TrimStrings(), new ConvertEmptyStringsToNull()],
            new Request(body: ['phone' => '   '])
        );

        $this->assertNull(
            $request->input('phone'),
            'A whitespace-only field must become null when TrimStrings runs before ConvertEmptyStringsToNull.'
        );
    }

    /**
     * The other order, to pin that the ordering genuinely matters rather than
     * being a coincidence of the previous test.
     */
    public function test_whitespace_only_survives_when_conversion_runs_first(): void
    {
        $request = $this->throughMany(
            [new ConvertEmptyStringsToNull(), new TrimStrings()],
            new Request(body: ['phone' => '   '])
        );

        $this->assertSame('', $request->input('phone'));
    }

    public function test_a_nested_empty_string_becomes_null_at_the_leaf(): void
    {
        $request = $this->through(
            new ConvertEmptyStringsToNull(),
            new Request(body: ['lead' => ['phone' => '', 'name' => 'Ada']])
        );

        $this->assertSame(['phone' => null, 'name' => 'Ada'], $request->input('lead'));
    }

    // ---------------------------------------------------------- SanitizeInput

    public function test_sanitize_input_strips_tags(): void
    {
        $request = $this->through(
            new SanitizeInput(),
            new Request(body: ['name' => '<script>alert(1)</script>Ada'])
        );

        $this->assertStringNotContainsString('<script>', (string) $request->input('name'));
        $this->assertStringContainsString('Ada', (string) $request->input('name'));
    }

    /**
     * The WordPress slashing convention. `$_POST['name']` for a typed `O'Brien`
     * really holds `O\'Brien`; sanitizing without unslashing first would mangle
     * it. This asserts the value survives intact, which is the observable
     * difference between the two orders.
     */
    public function test_sanitize_input_unslashes_before_sanitizing(): void
    {
        $request = $this->through(
            new SanitizeInput(),
            new Request(body: ['name' => "O\\'Brien"])
        );

        $this->assertSame("O'Brien", $request->input('name'));
    }

    /**
     * Multi-line input collapses to one line — WordPress's decision for
     * single-line text, and the behaviour a project must know about before
     * enabling this globally. Pinned because it is surprising.
     */
    public function test_sanitize_input_collapses_line_breaks(): void
    {
        $request = $this->through(
            new SanitizeInput(),
            new Request(body: ['notes' => "first\nsecond"])
        );

        $this->assertSame('first second', $request->input('notes'));
    }

    public function test_sanitize_input_walks_nested_arrays(): void
    {
        $request = $this->through(
            new SanitizeInput(),
            new Request(body: ['lead' => ['name' => '<b>Ada</b>']])
        );

        $this->assertSame(['name' => 'Ada'], $request->input('lead'));
    }

    /**
     * `sanitize_text_field()` is WordPress's; outside WordPress there is no
     * framework-native equivalent that means the same thing, so the value passes
     * through rather than being given a near-miss wash that behaves differently
     * in production. The stub defines the function, so this pins the *guard*
     * rather than the stub's behaviour — see the class docblock for why this one
     * fails open where the guards fail closed.
     */
    public function test_sanitize_input_is_a_no_op_for_non_strings(): void
    {
        $request = $this->through(
            new SanitizeInput(),
            new Request(body: ['count' => 42, 'tags' => ['a', 'b']])
        );

        $this->assertSame(42, $request->input('count'));
        $this->assertSame(['a', 'b'], $request->input('tags'));
    }

    // --------------------------------------------------------- the full stack

    /**
     * The three together, in the order `routes/web.php` declares them — which is
     * the configuration a project actually ships.
     */
    public function test_the_shipped_stack_cleans_a_form_submission(): void
    {
        $request = $this->throughMany(
            [new TrimStrings(), new ConvertEmptyStringsToNull(), new SanitizeInput()],
            new Request(body: [
                'name'  => '  <b>Ada</b>  ',
                'email' => ' ada@example.test ',
                'phone' => '   ',
            ])
        );

        $this->assertSame('Ada', $request->input('name'));
        $this->assertSame('ada@example.test', $request->input('email'));
        $this->assertNull($request->input('phone'));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run one middleware and capture the request it passed on.
     */
    private function through(
        \Framework\Http\Contracts\Middleware $middleware,
        Request $request
    ): Request {
        return $this->throughMany([$middleware], $request);
    }

    /**
     * Run a stack and capture the request that reached the destination.
     *
     * @param list<\Framework\Http\Contracts\Middleware> $middleware
     */
    private function throughMany(array $middleware, Request $request): Request
    {
        $captured = null;

        $classes = [];

        foreach ($middleware as $index => $instance) {
            // Bound by class name, because the pipeline resolves by the string the
            // caller listed. Each gets a distinct key so a stack of three is
            // three layers rather than one repeated.
            $key = $instance::class . '#' . $index;
            $this->container->instance($key, $instance);
            $classes[] = $key;
        }

        (new Pipeline($this->container))
            ->send($request)
            ->through($classes)
            ->then(function (Request $inner) use (&$captured): ResponseInterface {
                $captured = $inner;

                return new JsonResponse(['ok' => true]);
            });

        $this->assertInstanceOf(Request::class, $captured);

        return $captured;
    }
}
