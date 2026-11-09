<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Exceptions\DomainValidationException;
use Framework\Config\Config;
use Framework\Http\Exceptions\Handler;
use Framework\Http\Exceptions\HttpException;
use Framework\Http\Exceptions\NotFoundHttpException;
use Framework\Http\Exceptions\TooManyRequestsHttpException;
use Framework\Http\Exceptions\ValidationHttpException;
use Framework\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HandlerTest extends TestCase
{
    /**
     * The `APP_DEBUG` value in force before this case touched it, so tearDown can
     * put it back.
     *
     * `$_ENV` is process-global and several other cases in the suite read config,
     * so a case that leaves `APP_DEBUG=true` behind changes what *they* load. The
     * restore is not tidiness: without it the leak shows up as an unrelated test
     * failing, which is the most expensive kind of failure to diagnose.
     */
    private bool $hadDebug = false;
    private string|false $previousDebug = false;

    protected function setUp(): void
    {
        $this->hadDebug = array_key_exists('APP_DEBUG', $_ENV);
        $this->previousDebug = $_ENV['APP_DEBUG'] ?? false;
    }

    protected function tearDown(): void
    {
        if ($this->hadDebug) {
            $_ENV['APP_DEBUG'] = $this->previousDebug;
        } else {
            unset($_ENV['APP_DEBUG']);
        }

        // The config is a static store loaded once per process; leaving this
        // case's value in it would outlive the case.
        Config::flush();
    }

    /**
     * A handler built against the real config, with `APP_DEBUG` set as asked.
     *
     * The env var is set and the config reloaded rather than a value being poked
     * into the store, because `Config` has no setter — deliberately, since a
     * mutable global config is how a suite acquires an order dependency — and
     * because going through the real `config/app.php` is what proves the flag is
     * actually *read from there* rather than from a default the test also
     * supplied.
     */
    private function handler(
        bool $debug = false,
        ?TransactionManagerContract $transactions = null,
        ?callable $reporter = null
    ): Handler {
        $_ENV['APP_DEBUG'] = $debug ? 'true' : 'false';

        Config::flush();
        Config::load(dirname(__DIR__, 3) . '/config');

        // `new Config()` rather than the resolved class-string: the class is
        // entirely static, so an instance is a handle to the shared store rather
        // than a copy of it, and this is the same object HttpServiceProvider
        // binds. `get()` is called on the instance and reads the static state the
        // two lines above just loaded.
        return new Handler(new Config(), $transactions, $reporter);
    }

    /**
     * A transaction manager that records the two calls the handler is allowed to
     * make and nothing else.
     *
     * Written rather than mocked because the *order* of the calls is what is under
     * test in one case, and a mock's expectation syntax asserts order weakly
     * enough to pass on a handler that rolls back after rendering.
     *
     * @param list<string> $calls
     */
    private function transactions(bool $inTransaction, array &$calls): TransactionManagerContract
    {
        return new class ($inTransaction, $calls) implements TransactionManagerContract {
            /** @param list<string> $calls */
            public function __construct(private bool $open, private array &$calls)
            {
            }

            public function transaction(callable $callback): mixed
            {
                return $callback();
            }

            /**
             * The contract's second name for {@see transaction()}, delegated so
             * the two cannot disagree about depth.
             */
            public function run(callable $callback): mixed
            {
                return $this->transaction($callback);
            }

            public function beginTransaction(): void
            {
                $this->calls[] = 'beginTransaction';
            }

            public function commit(): void
            {
                $this->calls[] = 'commit';
            }

            public function inTransaction(): bool
            {
                $this->calls[] = 'inTransaction';

                return $this->open;
            }

            public function rollback(): void
            {
                $this->calls[] = 'rollback';
                $this->open = false;
            }
        };
    }

    // ------------------------------------------------------------- the mapping

    /**
     * A request that asks for JSON.
     *
     * Needed explicitly rather than relying on a default, because the handler
     * chooses its response shape from the request and an `Accept`-less request
     * gets HTML — where the body is `html()` and there is no `data()` at all. A
     * test reading `data()` off an HTML response fails with "undefined method",
     * which names the projection rather than the missing header.
     *
     * `accept` is spelled lowercase because `Request::$headers` is documented to
     * hold lowercased names and `header()` looks up `strtolower($name)` — so an
     * `'Accept'` key is silently invisible, and the request quietly renders as
     * HTML.
     */
    private function jsonRequest(): Request
    {
        return new Request(headers: ['accept' => 'application/json']);
    }

    public function test_a_domain_validation_exception_becomes_a_422_with_its_field_errors(): void
    {
        $domain = DomainValidationException::field('email', 'That address is not valid.', 'bad_email');

        $response = $this->handler()->handle($domain, $this->jsonRequest());

        $this->assertSame(422, $response->status());

        $body = $response->data();
        $this->assertSame('validation_failed', $body['error']);
        $this->assertSame('That address is not valid.', $body['message']);
        $this->assertSame('bad_email', $body['code']);
        $this->assertSame(['email' => ['That address is not valid.']], $body['errors']);
    }

    public function test_the_domain_exception_itself_carries_no_status(): void
    {
        // The golden rule, asserted rather than assumed: a type in `Core\` that
        // knew about 422 would make the domain layer depend on the protocol, and
        // the mapping below would be pointless indirection.
        $domain = DomainValidationException::field('email', 'Nope.');

        $this->assertInstanceOf(\DomainException::class, $domain);
        $this->assertFalse(method_exists($domain, 'statusCode'));
    }

    public function test_an_http_exception_passes_through_with_its_own_status(): void
    {
        $response = $this->handler()->handle(new NotFoundHttpException('No such lead.'), $this->jsonRequest());

        $this->assertSame(404, $response->status());
        $this->assertSame('not_found', $response->data()['error']);
        $this->assertSame('No such lead.', $response->data()['message']);
    }

    public function test_a_429_keeps_its_retry_after_header(): void
    {
        // The header is the only actionable part of a 429. A handler that dropped
        // it would produce a correct status a client could do nothing with.
        //
        // Read from `options()` rather than the body: headers travel as response
        // options, not as part of the JSON envelope, because they are written to
        // the wire by `send()` rather than serialized into the payload.
        $response = $this->handler()->handle(new TooManyRequestsHttpException(30), $this->jsonRequest());

        $this->assertSame(429, $response->status());
        $this->assertSame('too_many_requests', $response->data()['error']);
        $this->assertSame('30', $response->options()['headers']['Retry-After']);
    }

    public function test_an_unexpected_throwable_becomes_a_500_with_a_generic_message(): void
    {
        $leaky = new RuntimeException('SQLSTATE[HY000]: MySQL server has gone away');

        $response = $this->handler(debug: false)->handle($leaky, $this->jsonRequest());

        $this->assertSame(500, $response->status());
        $this->assertSame('server_error', $response->data()['error']);

        // The whole point: the internal detail does not reach the client.
        $this->assertSame('An unexpected error occurred.', $response->data()['message']);
        $this->assertStringNotContainsString('SQLSTATE', $response->data()['message']);
    }

    public function test_the_real_message_is_shown_when_debug_is_on(): void
    {
        $response = $this->handler(debug: true)->handle(new RuntimeException('boom'), $this->jsonRequest());

        $this->assertSame('boom', $response->data()['message']);
    }

    public function test_a_string_false_debug_value_is_not_truthy(): void
    {
        // `'false'` is a truthy string in PHP, and a config file or a test writing
        // it directly is the classic way a debug flag ends up permanently on —
        // leaking internals in production.
        //
        // The literal is written into `$_ENV` rather than loaded from a config
        // file, because `env()` is what would normally coerce it and going around
        // `env()` is exactly the mistake being guarded against: a config file that
        // assigns the string directly, or a project that replaces `app.php`.
        $_ENV['APP_DEBUG'] = 'false';

        Config::flush();
        Config::load(dirname(__DIR__, 3) . '/config');

        $response = (new Handler(new Config()))->handle(new RuntimeException('secret'), $this->jsonRequest());

        $this->assertSame('An unexpected error occurred.', $response->data()['message']);
    }

    public function test_a_deliberate_500_keeps_its_own_message(): void
    {
        // Raised as an HttpException, so the author wrote the message to be read.
        // Withholding it would make the raise site a lie.
        $response = $this->handler(debug: false)->handle(
            new HttpException('The payment gateway is unavailable.', 503),
            $this->jsonRequest()
        );

        $this->assertSame(503, $response->status());
        $this->assertSame('The payment gateway is unavailable.', $response->data()['message']);
    }

    public function test_a_deliberate_500_that_wraps_a_cause_still_keeps_its_own_message(): void
    {
        // `viewFailed()` is a *raised* 500 that carries the render error as its
        // previous. Telling "raised" from "wrapped" by `previous` would classify
        // this as accidental and replace the message with the generic one — so the
        // distinction is recorded at the wrap site instead, and this pins that.
        $response = $this->handler(debug: false)->handle(
            HttpException::viewFailed('leads.index', new RuntimeException('/secret/path/leads/index.php')),
            $this->jsonRequest()
        );

        $this->assertSame(500, $response->status());
        $this->assertStringContainsString('leads.index', $response->data()['message']);
    }

    public function test_a_wrapped_server_fault_is_hidden_even_though_it_has_a_previous(): void
    {
        // The mirror of the case above: same status, same shape, opposite outcome.
        // A repository's failure is diagnostic text about the internals.
        $response = $this->handler(debug: false)->handle(
            new RuntimeException('SQLSTATE[HY000]: MySQL server has gone away'),
            $this->jsonRequest()
        );

        $this->assertSame(500, $response->status());
        $this->assertSame('An unexpected error occurred.', $response->data()['message']);
    }

    // ---------------------------------------------------------------- rollback

    public function test_an_open_transaction_is_rolled_back_before_the_response_is_built(): void
    {
        $calls = [];
        $handler = $this->handler(transactions: $this->transactions(true, $calls));

        $handler->handle(new RuntimeException('boom'), $this->jsonRequest());

        // Order is the assertion. Rolling back after rendering would leave the
        // transaction open across a render that can itself throw — and `$wpdb`
        // closes an open transaction by committing it.
        $this->assertSame(['inTransaction', 'rollback'], $calls);
    }

    public function test_no_rollback_is_issued_when_no_transaction_is_open(): void
    {
        $calls = [];
        $handler = $this->handler(transactions: $this->transactions(false, $calls));

        $handler->handle(new RuntimeException('boom'), $this->jsonRequest());

        $this->assertSame(['inTransaction'], $calls);
    }

    public function test_a_failing_rollback_does_not_replace_the_original_failure(): void
    {
        $logged = [];

        $broken = new class implements TransactionManagerContract {
            public function transaction(callable $callback): mixed
            {
                return $callback();
            }

            public function run(callable $callback): mixed
            {
                return $callback();
            }

            public function beginTransaction(): void
            {
            }

            public function commit(): void
            {
            }

            public function inTransaction(): bool
            {
                return true;
            }

            public function rollback(): void
            {
                throw new RuntimeException('no connection');
            }
        };

        $response = $this->handler(
            transactions: $broken,
            reporter: function (string $line) use (&$logged): void {
                $logged[] = $line;
            }
        )->handle(new RuntimeException('the real problem'), new Request());

        // The client still gets the original failure's 500, not a second one.
        $this->assertSame(500, $response->status());

        // And the rollback failure reached the log rather than vanishing.
        $this->assertStringContainsString('failed to roll back', implode("\n", $logged));
    }

    public function test_handling_without_a_transaction_manager_is_allowed(): void
    {
        // A project with no database binds none, and the error path must not
        // depend on a binding that may not exist — a container error here would
        // replace a handled failure with an unhandled one.
        $response = $this->handler(transactions: null)->handle(new RuntimeException('boom'), $this->jsonRequest());

        $this->assertSame(500, $response->status());
    }

    public function test_every_throwable_is_reported_even_when_the_message_is_withheld(): void
    {
        $logged = [];

        $this->handler(
            debug: false,
            reporter: function (string $line) use (&$logged): void {
                $logged[] = $line;
            }
        )->handle(new RuntimeException('the schema is wrong'), $this->jsonRequest());

        // Withheld from the client, but the detail has to reach someone.
        $this->assertStringContainsString('the schema is wrong', implode("\n", $logged));
    }

    // ------------------------------------------------------------------- shape

    public function test_an_html_client_gets_an_escaped_page(): void
    {
        $response = $this->handler(debug: true)->handle(
            new RuntimeException('<script>alert(1)</script>'),
            new Request(headers: ['accept' => 'text/html'])
        );

        $this->assertSame(500, $response->status());

        // `html()` rather than `content()`: an HTML response carries markup, and
        // the escaping is the assertion — a raw `<script>` here would be an
        // attacker-controlled exception message rendered as live markup on the
        // error page.
        $markup = $response->html();
        $this->assertStringNotContainsString('<script>', $markup);
        $this->assertStringContainsString('&lt;script&gt;', $markup);
    }

    public function test_a_json_client_gets_json(): void
    {
        $response = $this->handler()->handle(new NotFoundHttpException(), $this->jsonRequest());

        $this->assertInstanceOf(\Framework\Http\Responses\JsonResponse::class, $response);
    }

    public function test_the_rest_error_shape_carries_status_body_and_headers(): void
    {
        $error = $this->handler()->toRestError(new TooManyRequestsHttpException(45));

        $this->assertSame(429, $error['status']);
        $this->assertSame('too_many_requests', $error['body']['error']);
        $this->assertSame('45', $error['headers']['Retry-After']);
    }

    public function test_the_rest_path_also_rolls_back(): void
    {
        $calls = [];

        $this->handler(transactions: $this->transactions(true, $calls))->toRestError(new RuntimeException('x'));

        $this->assertSame(['inTransaction', 'rollback'], $calls);
    }

    public function test_a_mapped_validation_error_carries_no_errors_key_when_there_are_none(): void
    {
        // The envelope stays parseable by one reader: `errors` appears only when
        // there is something in it.
        $response = $this->handler()->handle(new NotFoundHttpException(), $this->jsonRequest());

        $this->assertArrayNotHasKey('errors', $response->data());
    }

    public function test_a_validation_http_exception_raised_directly_also_renders_its_errors(): void
    {
        $response = $this->handler()->handle(
            new ValidationHttpException('Invalid.', ['name' => ['Required.']], 'invalid_input'),
            $this->jsonRequest()
        );

        $this->assertSame(422, $response->status());
        $this->assertSame(['name' => ['Required.']], $response->data()['errors']);
        $this->assertSame('invalid_input', $response->data()['code']);
    }

    public function test_the_slug_is_derived_from_the_status_not_the_class(): void
    {
        // So a client switching on `error` does not break when the framework adds
        // a subclass for the same status.
        $response = $this->handler()->handle(new HttpException('Denied.', 403), $this->jsonRequest());

        $this->assertSame('forbidden', $response->data()['error']);
    }
}
