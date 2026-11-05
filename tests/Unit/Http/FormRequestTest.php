<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use Framework\Http\Request;
use Framework\Http\Requests\FormRequest;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\RedirectResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use Tests\Unit\Http\Fixtures\PingRequest;
use Tests\Unit\Http\Fixtures\RedirectingRequest;
use Tests\Unit\Http\Fixtures\StoreLeadRequest;

/**
 * The dual-mode form request, exercised through its own public surface.
 *
 * The binder's side of the contract — that a failing form request stops the
 * action from running — is covered in {@see \Tests\Unit\Routing\FormRequestBindingTest},
 * because it is the binder's behaviour and not this class's. What is tested here
 * is everything a form request decides on its own: what fails, what the failure
 * looks like to each kind of client, and what `validated()` hands back.
 */
final class FormRequestTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function request(array $body = [], array $headers = [], string $rawBody = ''): Request
    {
        return new Request(
            query: [],
            body: $body,
            server: ['REQUEST_METHOD' => 'POST'],
            headers: $headers,
            rawBody: $rawBody
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: StoreLeadRequest, 1: Request}
     */
    private function form(array $body, array $headers = []): array
    {
        $request = $this->request($body, $headers);

        return [StoreLeadRequest::fromRequest($request), $request];
    }

    // ------------------------------------------------------------------ passing

    public function test_valid_data_passes(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => 'Ada']);
        $form->validate();

        $this->assertTrue($form->passes());
        $this->assertSame([], $form->errors());
    }

    public function test_validated_returns_only_the_ruled_keys(): void
    {
        [$form] = $this->form([
            'email' => 'a@b.test',
            'name' => 'Ada',
            'is_admin' => 'yes',
        ]);
        $form->validate();

        $this->assertSame(
            ['email' => 'a@b.test', 'name' => 'Ada'],
            $form->validated(),
            'A field with no rule must not reach validated().'
        );
    }

    public function test_validated_preserves_the_raw_types(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => 'Ada', 'age' => '30']);
        $form->validate();

        $this->assertSame('30', $form->validated()['age'], 'validated() must not silently cast.');
    }

    public function test_validated_omits_an_absent_optional_field(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => 'Ada']);
        $form->validate();

        $this->assertArrayNotHasKey('age', $form->validated());
    }

    public function test_validated_rebuilds_a_dotted_path_as_a_nested_array(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => 'Ada', 'tags' => ['php']]);
        $form->validate();

        $this->assertSame(['php'], $form->validated()['tags']);
    }

    public function test_safe_falls_back_to_a_default(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => 'Ada']);
        $form->validate();

        $this->assertSame('guest', $form->safe('role', 'guest'));
    }

    // ------------------------------------------------------------------ failing

    public function test_fails_when_a_rule_fails(): void
    {
        [$form] = $this->form(['email' => 'nope', 'name' => 'Ada']);
        $form->validate();

        $this->assertTrue($form->fails());
        $this->assertArrayHasKey('email', $form->errors());
    }

    public function test_first_error_returns_one_message(): void
    {
        [$form] = $this->form(['email' => '', 'name' => 'Ada']);
        $form->validate();

        $this->assertIsString($form->firstError('email'));
    }

    public function test_first_error_is_null_for_a_passing_field(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => 'Ada']);
        $form->validate();

        $this->assertNull($form->firstError('email'));
    }

    public function test_a_message_override_replaces_the_default(): void
    {
        [$form] = $this->form(['email' => '', 'name' => 'Ada']);
        $form->validate();

        $this->assertSame('We need your email address.', $form->firstError('email'));
    }

    public function test_an_attribute_wide_override_applies_to_every_rule(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => '']);
        $form->validate();

        $this->assertSame('Your name is required.', $form->firstError('name'));
    }

    public function test_a_wildcard_element_failure_is_reported(): void
    {
        [$form] = $this->form([
            'email' => 'a@b.test',
            'name' => 'Ada',
            'tags' => ['ok', 'waytoolong'],
        ]);
        $form->validate();

        $this->assertTrue($form->fails());
        $this->assertArrayHasKey('tags.1', $form->errors());
    }

    // ---------------------------------------------------------------- authorize

    public function test_authorize_runs_only_when_the_rules_pass(): void
    {
        [$form] = $this->form(['email' => 'nope', 'name' => 'Ada']);
        $form->validate();

        $this->assertSame(0, $form->authorizeCalls, 'authorize() must not run on structurally bad data.');
    }

    public function test_authorize_refusal_attaches_to_the_form_bucket(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => 'Ada']);
        $form->deny = true;
        $form->denyMessage = 'You may not do that.';
        $form->validate();

        $this->assertTrue($form->fails());
        $this->assertSame('You may not do that.', $form->firstError('_form'));
    }

    public function test_authorize_returning_false_gets_a_default_message(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => 'Ada']);
        $form->deny = true;
        $form->validate();

        $this->assertSame('This action is unauthorized.', $form->firstError('_form'));
    }

    public function test_validate_is_idempotent(): void
    {
        [$form] = $this->form(['email' => '', 'name' => 'Ada']);

        $form->validate();
        $first = $form->errors();
        $form->validate();

        $this->assertSame($first, $form->errors(), 'A second validate() must not append duplicate messages.');
        $this->assertCount(2, $form->errors()['email']);
    }

    // ------------------------------------------------------------------- web mode

    public function test_a_browser_failure_is_a_redirect(): void
    {
        [$form] = $this->form(['email' => 'nope', 'name' => 'Ada']);
        $form->validate();

        $this->assertInstanceOf(RedirectResponse::class, $form->failureResponse());
    }

    public function test_the_redirect_flashes_the_errors(): void
    {
        [$form] = $this->form(['email' => 'nope', 'name' => 'Ada']);
        $form->validate();

        /** @var RedirectResponse $response */
        $response = $form->failureResponse();

        $this->assertArrayHasKey('email', $response->flash()['errors']);
    }

    public function test_the_redirect_flashes_the_old_input(): void
    {
        [$form] = $this->form(['email' => 'nope', 'name' => 'Ada']);
        $form->validate();

        /** @var RedirectResponse $response */
        $response = $form->failureResponse();

        $this->assertSame('Ada', $response->flash()['old']['name']);
    }

    /**
     * The single most important thing `old input` must not do: a password field
     * repopulated from a flash value has been written into the options table and
     * into the next page's HTML.
     */
    public function test_the_redirect_never_flashes_a_password(): void
    {
        [$form] = $this->form([
            'email' => 'nope',
            'name' => 'Ada',
            'password' => 'hunter2hunter2',
            'password_confirmation' => 'hunter2hunter2',
        ]);
        $form->validate();

        /** @var RedirectResponse $response */
        $response = $form->failureResponse();
        $old = $response->flash()['old'];

        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('password_confirmation', $old);
    }

    public function test_the_redirect_never_flashes_a_nonce(): void
    {
        [$form] = $this->form([
            'email' => 'nope',
            'name' => 'Ada',
            '_wpnonce' => 'abc123',
            '_method' => 'POST',
        ]);
        $form->validate();

        /** @var RedirectResponse $response */
        $response = $form->failureResponse();
        $old = $response->flash()['old'];

        $this->assertArrayNotHasKey('_wpnonce', $old);
        $this->assertArrayNotHasKey('_method', $old);
    }

    public function test_the_redirect_flashes_a_summary_message(): void
    {
        [$form] = $this->form(['email' => 'nope', 'name' => 'Ada']);
        $form->validate();

        /** @var RedirectResponse $response */
        $response = $form->failureResponse();

        $this->assertNotEmpty($response->flash()['error']);
    }

    public function test_the_redirect_target_can_be_stated_by_the_request_class(): void
    {
        $form = RedirectingRequest::fromRequest($this->request(['email' => 'nope']));
        $form->validate();

        /** @var RedirectResponse $response */
        $response = $form->failureResponse();

        $this->assertSame('https://example.test/somewhere', $response->url());
    }

    public function test_the_redirect_target_can_come_from_a_form_field(): void
    {
        [$form] = $this->form([
            'email' => 'nope',
            'name' => 'Ada',
            'redirect_to' => 'https://example.test/wp-admin/admin.php?page=leads',
        ]);
        $form->validate();

        /** @var RedirectResponse $response */
        $response = $form->failureResponse();

        $this->assertStringContainsString('page=leads', $response->url());
    }

    /**
     * A referer from another site must not become the redirect target — that is
     * an open redirect however well-meant the intent.
     */
    public function test_an_offsite_referer_is_not_used_as_the_redirect_target(): void
    {
        [$form] = $this->form(
            ['email' => 'nope', 'name' => 'Ada'],
            ['referer' => 'https://evil.test/steal']
        );
        $form->validate();

        /** @var RedirectResponse $response */
        $response = $form->failureResponse();

        $this->assertStringNotContainsString('evil.test', $response->url());
    }

    public function test_an_onsite_referer_is_used_as_the_redirect_target(): void
    {
        WpStub::$homeUrl = 'https://example.test';

        [$form] = $this->form(
            ['email' => 'nope', 'name' => 'Ada'],
            ['referer' => 'https://example.test/leads/new']
        );
        $form->validate();

        /** @var RedirectResponse $response */
        $response = $form->failureResponse();

        $this->assertSame('https://example.test/leads/new', $response->url());
    }

    // ------------------------------------------------------------------- api mode

    public function test_an_accept_header_switches_to_json(): void
    {
        [$form] = $this->form(['email' => 'nope', 'name' => 'Ada'], ['accept' => 'application/json']);
        $form->validate();

        $this->assertInstanceOf(JsonResponse::class, $form->failureResponse());
    }

    public function test_a_json_failure_is_a_422(): void
    {
        [$form] = $this->form(['email' => 'nope', 'name' => 'Ada'], ['accept' => 'application/json']);
        $form->validate();

        $this->assertSame(422, $form->failureResponse()->status());
    }

    public function test_a_json_failure_carries_the_errors_by_field(): void
    {
        [$form] = $this->form(['email' => 'nope', 'name' => 'Ada'], ['accept' => 'application/json']);
        $form->validate();

        /** @var JsonResponse $response */
        $response = $form->failureResponse();
        $data = $response->data();

        $this->assertSame('validation_failed', $data['error']);
        $this->assertArrayHasKey('email', $data['errors']);
    }

    /**
     * A JSON *body* is itself a declaration that the client speaks JSON — PHP
     * does not populate $_POST for it, so the Accept header is often the only
     * thing missing on a request that is otherwise entirely JSON.
     */
    public function test_a_json_content_type_switches_to_json_without_an_accept_header(): void
    {
        $request = $this->request(
            body: [],
            headers: ['content-type' => 'application/json'],
            rawBody: (string) json_encode(['email' => 'nope'])
        );

        $form = PingRequest::fromRequest($request);
        $form->validate();

        $this->assertInstanceOf(JsonResponse::class, $form->failureResponse());
    }

    public function test_a_json_failure_does_not_touch_a_transient(): void
    {
        [$form] = $this->form(['email' => 'nope', 'name' => 'Ada'], ['accept' => 'application/json']);
        $form->validate();
        $form->failureResponse();

        $this->assertSame([], WpStub::$transientWrites, 'A JSON client must not get a flashed redirect.');
    }

    // --------------------------------------------------------------- rest mode

    /**
     * A REST callback's return value is serialized by WordPress, not sent — so a
     * framework response object would reach the client as an unreadable object
     * and the 422 would never happen. `WP_Error` is the only shape the REST
     * server turns into a proper error response.
     */
    public function test_a_rest_dispatch_gets_a_wp_error(): void
    {
        FormRequest::$restDispatch = true;

        try {
            [$form] = $this->form(['email' => 'nope', 'name' => 'Ada']);
            $form->validate();

            $this->assertInstanceOf(\WP_Error::class, $form->failureResponse());
        } finally {
            FormRequest::$restDispatch = false;
        }
    }

    public function test_a_rest_error_carries_a_422_status(): void
    {
        FormRequest::$restDispatch = true;

        try {
            [$form] = $this->form(['email' => 'nope', 'name' => 'Ada']);
            $form->validate();

            $this->assertSame(422, $form->failureResponse()->get_status());
        } finally {
            FormRequest::$restDispatch = false;
        }
    }

    public function test_a_rest_error_carries_the_errors_by_field(): void
    {
        FormRequest::$restDispatch = true;

        try {
            [$form] = $this->form(['email' => 'nope', 'name' => 'Ada']);
            $form->validate();

            $data = $form->failureResponse()->get_error_data();

            $this->assertSame('validation_failed', $form->failureResponse()->get_error_code());
            $this->assertArrayHasKey('email', $data['errors']);
        } finally {
            FormRequest::$restDispatch = false;
        }
    }

    /**
     * The marker is a static, so a dispatch that forgot to clear it would make
     * the next web request answer with a `WP_Error` — the failure mode a browser
     * cannot render.
     */
    public function test_the_rest_marker_defaults_to_off(): void
    {
        $this->assertFalse(FormRequest::$restDispatch);
    }

    // ------------------------------------------------------------------ misuse

    public function test_failure_response_before_validation_is_refused(): void
    {
        $form = StoreLeadRequest::fromRequest($this->request(['email' => 'nope']));

        $this->expectException(\LogicException::class);

        $form->failureResponse();
    }

    /**
     * The container builds a form request with no arguments, so a misuse that
     * skipped {@see \Framework\Http\Requests\FormRequest::fromRequest()} would
     * otherwise validate an empty payload and report every required field as
     * missing — "the form rejects everything" rather than "the wiring is wrong".
     */
    public function test_validating_without_a_request_is_refused(): void
    {
        $form = new StoreLeadRequest();

        $this->expectException(\LogicException::class);

        $form->validate();
    }

    public function test_set_request_allows_direct_construction(): void
    {
        $form = new StoreLeadRequest();
        $form->setRequest($this->request(['email' => 'a@b.test', 'name' => 'Ada']));
        $form->validate();

        $this->assertTrue($form->passes());
    }

    public function test_input_reads_through_to_the_wrapped_request(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => 'Ada']);

        $this->assertSame('a@b.test', $form->input('email'));
        $this->assertSame('fallback', $form->input('missing', 'fallback'));
    }

    public function test_all_reads_through_to_the_wrapped_request(): void
    {
        [$form] = $this->form(['email' => 'a@b.test', 'name' => 'Ada']);

        $this->assertArrayHasKey('email', $form->all());
    }
}
