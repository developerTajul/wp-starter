<?php
declare(strict_types=1);

namespace Framework\Http\Exceptions;

use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Exceptions\DomainValidationException;
use Framework\Config\Config;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\HtmlResponse;
use Framework\Http\Responses\JsonResponse;
use Throwable;

/**
 * The one place an uncaught throwable becomes a response.
 *
 * Why this exists
 *
 * Before it, every failure path handled its own error locally: the web adapter
 * caught {@see HttpException} and rendered a page, the REST adapter caught two
 * routing exception types and returned a `WP_Error`, and the middleware each
 * rendered their own 403. That worked for the failures someone had thought of.
 * Everything else - a `RuntimeException` from a repository, a `LogicException`
 * from a resource reading a field that is not there, a `TypeError` - escaped into
 * WordPress and became an opaque fatal with no status code and nothing naming the
 * route.
 *
 * This class is the net under all of them.
 *
 * Three jobs, in this order, and the order is load-bearing
 *
 * 1. **Roll back.** If a unit of work is open, abandon it *first* - before
 *    logging, before rendering. See {@see rollbackOpenTransaction()}.
 * 2. **Report.** Write the failure somewhere an operator will find it.
 * 3. **Render.** Turn it into a response shaped for whoever is asking.
 *
 * Doing 3 before 1 would be a real bug rather than untidiness: rendering can
 * itself throw (a broken view, an unwritable log), and the transaction would be
 * left open with its fate undecided. `$wpdb` closes an open transaction by
 * committing it, so "left open" means "half the work becomes durable", which is
 * the exact outcome a transaction exists to prevent.
 *
 * The domain-to-HTTP mapping, and why it is here
 *
 *     DomainValidationException  ->  422 with the field errors
 *
 * `Core\` may not depend on `Framework\`, so the domain's exception carries no
 * status. This handler is the only seam that knows both zones, which makes it the
 * only correct home for the translation. A project that wants a different mapping
 * - 400 for a JSON API, a rendered form for an HTML screen - overrides
 * {@see mapDomainException()} rather than editing the domain.
 *
 * The debug branch
 *
 * `config('app.debug')` decides whether a client sees the real message or a
 * generic one. In production the message is withheld for anything that is not a
 * deliberate, client-facing HTTP exception, because an uncaught `RuntimeException`
 * from a repository leaks the schema - `SQLSTATE[HY000]: General error: 2006
 * MySQL server has gone away` names the engine, the driver and the failure mode,
 * to whoever asked. That is the leak a repository-backed service already worries
 * about one layer down; this is where it is actually prevented.
 *
 * A client *is* shown the message when the application raised it deliberately -
 * an {@see HttpException} and its subclasses - because those messages are written
 * to be read, and hiding them would make a 404 say "something went wrong". That
 * holds for deliberate 5xx too: a raised 503 saying the payment gateway is down
 * is a message an author wrote for a human, not a leak.
 *
 * Only a throwable the handler had to *wrap* - a repository's `SQLSTATE…`, a
 * `TypeError` - has its message withheld. The two are told apart by
 * {@see HttpException::wasWrappedByHandler()}, set at the wrap site.
 *
 * Reading config through the injected `Config`
 *
 * Per the project's rule, `env()` is never called outside `config/*.php`. The
 * debug flag is read through the container-bound {@see Config}, so a test can
 * flip it without touching the environment, and so the value is resolved once
 * rather than at each throw.
 */
class Handler
{
    /**
     * @param Config                          $config       Application configuration, for the debug flag.
     * @param TransactionManagerContract|null $transactions Asked to roll back before rendering.
     *                                                      Null when the application binds no transaction
     *                                                      manager - a project with no database has nothing
     *                                                      to roll back, and requiring a binding would make
     *                                                      the error path fail on a container error.
     * @param callable(string): void|null     $reporter     Where a failure is written. Defaults to
     *                                                      `error_log()`. Injected so a test can
     *                                                      assert what was reported without capturing
     *                                                      the error log.
     */
    public function __construct(
        private readonly Config $config,
        private readonly ?TransactionManagerContract $transactions = null,
        private $reporter = null
    ) {
    }

    /**
     * Handle a throwable and produce the response to send.
     *
     * The single entry point every adapter calls. Never throws: a handler that
     * could throw would replace one unhandled failure with another, and it runs
     * on the path least able to absorb a second one.
     */
    public function handle(Throwable $e, ?Request $request = null): ResponseInterface
    {
        // First, and unconditionally. See the class docblock for why this cannot
        // wait until after rendering.
        $this->rollbackOpenTransaction();

        $this->report($e);

        $http = $this->toHttpException($e);

        return $this->render($http, $request);
    }

    /**
     * The response body and status for a throwable, as an array.
     *
     * The seam for the REST adapters, which cannot send a {@see ResponseInterface}
     * - WordPress's REST server serializes whatever a callback returns and knows
     * nothing about this framework's responses. They call this and wrap the result
     * in a `WP_Error`, which keeps the *decisions* - status, envelope, whether the
     * message is safe - in one place while letting each domain send in its own
     * shape.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function toRestError(Throwable $e): array
    {
        $this->rollbackOpenTransaction();

        $this->report($e);

        $http = $this->toHttpException($e);

        return [
            'status'  => $http->statusCode(),
            'body'    => $this->body($http, expectsJson: true),
            'headers' => $http->headers(),
        ];
    }

    // ------------------------------------------------------------------ steps

    /**
     * Abandon any open unit of work.
     *
     * `inTransaction()`/`rollback()` are the write-only escape hatch on
     * {@see TransactionManagerContract}, and this is the caller they exist for.
     * The manager is asked rather than told because the common case is that no
     * transaction is open at all - most throwables are raised outside one - and
     * an unconditional `ROLLBACK` would be a warning at best.
     *
     * The generated `ROLLBACK` is issued with the exception already unwinding, so
     * a failure here must not become a second exception. `rollback()` itself is
     * contractually a no-op when nothing is open; anything else it raises is
     * swallowed and reported, because at this point there is no better outcome
     * available and an escape would lose the original throwable entirely.
     */
    private function rollbackOpenTransaction(): void
    {
        if ($this->transactions === null) {
            return;
        }

        try {
            if ($this->transactions->inTransaction()) {
                $this->transactions->rollback();
            }
        } catch (Throwable $rollbackFailure) {
            $this->write(sprintf(
                'WP Mini Laravel: failed to roll back an open transaction while handling an error: %s',
                $rollbackFailure->getMessage()
            ));
        }
    }

    /**
     * Write the failure where an operator will find it.
     *
     * Every throwable is reported, including the ones whose message is withheld
     * from the client. The whole point of withholding is that the detail still has
     * to reach *someone*, and the log is that someone.
     */
    private function report(Throwable $e): void
    {
        $this->write(sprintf(
            'WP Mini Laravel: unhandled %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    /**
     * Narrow a throwable into something with a status code.
     *
     * Three cases, and each is a deliberate policy:
     *
     *  - **Already an `HttpException`.** Passed through. The application raised it
     *    knowing what it meant; second-guessing the status here would make the
     *    raise site a lie.
     *  - **A domain validation exception.** Mapped by {@see mapDomainException()}.
     *  - **Anything else.** Wrapped as a 500. A `RuntimeException` from a
     *    repository or a `TypeError` from a bad call is a server fault, and
     *    reporting it as anything else would tell the client to fix their request.
     */
    private function toHttpException(Throwable $e): HttpException
    {
        if ($e instanceof HttpException) {
            return $e;
        }

        if ($e instanceof DomainValidationException) {
            return $this->mapDomainException($e);
        }

        // Already part of the framework's response vocabulary. A FormRequest's
        // failure response is built by the request itself, and re-wrapping it in
        // a status would flatten a redirect-with-errors into a 500.
        //
        // Marked as wrapped so {@see message()} can tell this accidental 500 from
        // a deliberately raised one - the two are indistinguishable by status, and
        // by `previous` as well, since a deliberate `viewFailed()` also wraps.
        return (new HttpException($e->getMessage(), 500, [], 0, $e))->markAsWrappedByHandler();
    }

    /**
     * Translate a domain violation into its HTTP meaning.
     *
     * The one line that joins the two zones. Override to change the policy - a
     * project with a JSON API and an HTML admin may want different statuses for
     * the same violation, and this is the only place either answer belongs.
     *
     * 422 rather than 400: the request was well-formed and understood, and the
     * *content* violated a rule. That is the distinction 422 exists to draw, and
     * it is the same one {@see \Core\Shared\Application\DTOs\Result} draws when a service reports
     * validation failure.
     */
    protected function mapDomainException(DomainValidationException $e): ValidationHttpException
    {
        return new ValidationHttpException(
            $e->getMessage(),
            $e->errors(),
            $e->errorCode(),
            [],
            $e
        );
    }

    /**
     * Build the response for a failure, shaped for the client.
     *
     * JSON when the client asked for it, HTML otherwise - the same rule the
     * middleware and adapters already applied by hand, now in one place so the
     * three cannot drift.
     */
    private function render(HttpException $e, ?Request $request): ResponseInterface
    {
        $wantsJson = $request === null || $request->expectsJson();

        if ($wantsJson) {
            return new JsonResponse($this->body($e, expectsJson: true), [
                'status_code' => $e->statusCode(),
                // Headers are passed through so a 429's `Retry-After` and a 405's
                // `Allow` survive to the wire. Without this the status would be
                // correct and the client still unable to act on it.
                'headers'     => $e->headers(),
            ]);
        }

        return new HtmlResponse(
            $this->html($e),
            $e->statusCode(),
            $e->headers()
        );
    }

    /**
     * The JSON body for a failure.
     *
     * A stable envelope - `error`, `message`, and `errors` only when there are
     * field errors - so a client can parse a 404 and a 422 with one reader. The
     * `error` slug is derived from the status rather than the exception class, so
     * a client switching on it does not break when the framework adds a subclass.
     *
     * @return array<string, mixed>
     */
    private function body(HttpException $e, bool $expectsJson): array
    {
        $body = [
            'error'   => $this->slug($e->statusCode()),
            'message' => $this->message($e),
        ];

        if ($e instanceof ValidationHttpException && $e->errors() !== []) {
            $body['errors'] = $e->errors();
        }

        if ($e instanceof ValidationHttpException && $e->errorCode() !== null) {
            $body['code'] = $e->errorCode();
        }

        return $body;
    }

    /**
     * The message a client may see.
     *
     * A deliberate HTTP exception's message is written to be read, so it is shown
     * regardless of debug. Anything that *became* a 500 - a `RuntimeException`
     * from a repository, a `TypeError` - has its message withheld unless debug is
     * on, because a server fault's message is diagnostic text about the internals
     * that names the schema, the driver and the failure mode.
     *
     * The distinction is whether the exception was **raised** as an HTTP exception
     * or **wrapped** into one, and status alone cannot draw it: by the time this
     * runs everything has been through {@see toHttpException()}. `$previous` cannot
     * draw it either - a deliberate `viewFailed()` wraps the render error that
     * caused it, and treating that as accidental would hide a message the author
     * wrote. So the fact is read from {@see HttpException::wasWrappedByHandler()},
     * which {@see toHttpException()} sets at the one site that knows.
     *
     * Without the distinction a deliberately raised 503 - "the payment gateway is
     * unavailable", written by an author for a human to read, would be replaced
     * by "An unexpected error occurred.", telling the client nothing while telling
     * the operator it was a bug.
     */
    private function message(HttpException $e): string
    {
        if ($e->statusCode() < 500) {
            return $e->getMessage();
        }

        // Raised rather than wrapped: the author chose both the status and the
        // wording, so the wording survives.
        if (!$e->wasWrappedByHandler()) {
            return $e->getMessage();
        }

        return $this->debug() ? $e->getMessage() : 'An unexpected error occurred.';
    }

    /**
     * The HTML body for a failure.
     *
     * Deliberately plain, and deliberately escaped. This is the last-resort page -
     * reached when a route failed before it could render anything, which means the
     * theme may not be available either - so it does not attempt to use the site's
     * markup. The message is escaped because in debug it carries a raw exception
     * message, and an exception message is data: a SQL error quoting attacker-
     * influenced input would otherwise be rendered as markup on the error page.
     */
    private function html(HttpException $e): string
    {
        $message = $this->message($e);

        $markup = sprintf(
            '<h1>%d</h1><p>%s</p>',
            $e->statusCode(),
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        );

        if ($e instanceof ValidationHttpException && $e->errors() !== []) {
            $markup .= '<ul>';

            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $one) {
                    $markup .= sprintf(
                        '<li><strong>%s</strong>: %s</li>',
                        htmlspecialchars((string) $field, ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars((string) $one, ENT_QUOTES, 'UTF-8')
                    );
                }
            }

            $markup .= '</ul>';
        }

        return $markup;
    }

    /**
     * A machine-readable slug for a status.
     *
     * Why not the exception's class name: a client switching on `error` would
     * break the day a subclass is introduced for the same status, which is a
     * refactor to the framework and a breaking change to the client. The status is
     * the part that is already a public contract.
     */
    private function slug(int $status): string
    {
        return match ($status) {
            400     => 'bad_request',
            401     => 'unauthenticated',
            403     => 'forbidden',
            404     => 'not_found',
            405     => 'method_not_allowed',
            422     => 'validation_failed',
            429     => 'too_many_requests',
            500     => 'server_error',
            503     => 'service_unavailable',
            default => $status >= 500 ? 'server_error' : 'request_failed',
        };
    }

    /**
     * Whether diagnostic detail may reach a client.
     *
     * Read through the injected config rather than `env()`, per the project's
     * rule. `(bool)` because `env()` coerces `'false'` to the boolean, but a config
     * file or a test may put the string there directly, and `'false'` is truthy in
     * PHP - the classic way a debug flag ends up permanently on.
     */
    private function debug(): bool
    {
        return (bool) $this->config->get('app.debug', false);
    }

    /**
     * Write one line to the configured reporter, never throwing.
     *
     * A logger that fails must not take down the error path it is reporting on.
     */
    private function write(string $line): void
    {
        try {
            if ($this->reporter !== null) {
                ($this->reporter)($line);

                return;
            }

            if (function_exists('error_log')) {
                error_log($line);
            }
        } catch (Throwable) {
            // Nothing further to do. The alternative is an exception escaping the
            // handler, which is worse than a lost log line by a wide margin.
        }
    }
}
