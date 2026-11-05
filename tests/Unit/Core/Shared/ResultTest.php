<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Shared;

use Core\Shared\Result;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The outcome type, on its own.
 *
 * Nothing here boots WordPress, touches the container, or reads config. That is
 * the property `core/` was designed for: if any test in this file needed a stub
 * to pass, `Result` would have picked up a dependency it is not supposed to
 * have — and the isolation rule would be a claim rather than a test.
 */
final class ResultTest extends TestCase
{
    // ------------------------------------------------------------------- success

    public function test_a_successful_result_is_ok_and_carries_its_value(): void
    {
        $result = Result::ok(['id' => 7]);

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isFailure());
        $this->assertSame(['id' => 7], $result->value());
    }

    /**
     * A use case that changes something and returns nothing is still a success.
     * If `ok()` required a value, every delete would have to invent one.
     */
    public function test_a_successful_result_may_carry_no_value(): void
    {
        $result = Result::ok();

        $this->assertTrue($result->isOk());
        $this->assertNull($result->value());
    }

    public function test_a_successful_result_carries_no_error(): void
    {
        $result = Result::ok('anything');

        $this->assertSame('', $result->message());
        $this->assertFalse($result->hasErrors());
        $this->assertSame([], $result->errors());
        $this->assertNull($result->errorCode());
    }

    /**
     * `toErrorPayload()` is the one door out to a transport. A success has no
     * error to report, and null is how the caller knows not to build one.
     */
    public function test_a_successful_result_has_no_error_payload(): void
    {
        $this->assertNull(Result::ok('anything')->toErrorPayload());
    }

    // ------------------------------------------------------------------- failure

    public function test_a_failed_result_is_not_ok_and_carries_no_value(): void
    {
        $result = Result::fail('Something went wrong.');

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isOk());
        $this->assertNull($result->value());
        $this->assertSame('Something went wrong.', $result->message());
    }

    public function test_fail_defaults_to_a_client_error_status(): void
    {
        $this->assertSame(Result::DEFAULT_ERROR_STATUS, Result::fail('Nope.')->status());
    }

    /**
     * @param callable(): Result $make
     * @param int                $status
     * @param string             $code
     *
     * @dataProvider namedFailures
     */
    public function test_each_named_constructor_carries_its_own_status_and_code(
        callable $make,
        int $status,
        string $code
    ): void {
        $result = $make();

        $this->assertTrue($result->isFailure());
        $this->assertSame($status, $result->status());
        $this->assertSame($code, $result->errorCode());
    }

    /**
     * @return array<string, array{callable(): Result, int, string}>
     */
    public static function namedFailures(): array
    {
        return [
            'notFound' => [static fn (): Result => Result::notFound(), 404, 'not_found'],
            'forbidden' => [static fn (): Result => Result::forbidden(), 403, 'forbidden'],
            'unauthenticated' => [static fn (): Result => Result::unauthenticated(), 401, 'unauthenticated'],
            'conflict' => [static fn (): Result => Result::conflict(), 409, 'conflict'],
        ];
    }

    /**
     * A client cannot tell from a 401 whether logging in would help, and cannot
     * tell from a 403 whether it should try at all. Collapsing them is the bug;
     * this is what keeps them apart.
     */
    public function test_unauthorized_and_unauthenticated_are_distinguishable(): void
    {
        $this->assertSame(401, Result::unauthenticated()->status());
        $this->assertSame(403, Result::forbidden()->status());
        $this->assertNotSame(
            Result::unauthenticated()->errorCode(),
            Result::forbidden()->errorCode()
        );
    }

    public function test_a_named_constructor_accepts_a_specific_message(): void
    {
        $this->assertSame('No note with that id.', Result::notFound('No note with that id.')->message());
    }

    // ----------------------------------------------------------------- validation

    public function test_validation_reports_every_field_that_failed(): void
    {
        $result = Result::validation([
            'title' => ['The title is required.'],
            'email' => ['That address is already in use.'],
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertSame(422, $result->status());
        $this->assertTrue($result->hasErrors());
        $this->assertCount(2, $result->errors());
    }

    /**
     * A field can break more than one rule. Keeping only the first message makes
     * the user fix them one submission apart, which is the experience this
     * signature exists to avoid.
     */
    public function test_validation_keeps_every_message_for_one_field(): void
    {
        $result = Result::validation([
            'email' => ['That address is already in use.', 'That address is malformed.'],
        ]);

        $this->assertSame(
            ['That address is already in use.', 'That address is malformed.'],
            $result->errors()['email']
        );
    }

    public function test_validation_carries_a_default_message_and_code(): void
    {
        $result = Result::validation(['title' => ['Required.']]);

        $this->assertSame('validation_failed', $result->errorCode());
        $this->assertNotSame('', $result->message());
    }

    /**
     * An empty error map is indistinguishable from a generic failure, which is
     * the one thing this constructor exists to express.
     */
    public function test_validation_refuses_an_empty_error_map(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one field');

        Result::validation([]);
    }

    // ------------------------------------------------------------- invariants

    public function test_a_failure_must_carry_a_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry a message');

        Result::fail('');
    }

    /**
     * @param int $status
     *
     * @dataProvider invalidStatuses
     */
    public function test_a_status_outside_the_http_range_is_refused(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid HTTP status code');

        Result::fail('Something failed.', $status);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidStatuses(): array
    {
        return [
            'zero' => [0],
            'below the range' => [99],
            'negative' => [-1],
            'above the range' => [600],
            'a typo' => [4040],
        ];
    }

    public function test_the_boundary_statuses_are_accepted(): void
    {
        $this->assertSame(100, Result::fail('Continue.', 100)->status());
        $this->assertSame(599, Result::fail('Network read timeout.', 599)->status());
    }

    // ------------------------------------------------------------------- valueOr

    /**
     * The bug this method shipped with.
     *
     * With `mixed $default = null`, "no default given" and "the default is null"
     * are the same call, so `valueOr()` on a failure could not throw — it had to
     * return null, silently turning a programming error into a value. Counting
     * the arguments is what separates the two requests, and this test is the
     * only thing that would catch a regression to the single-branch version.
     */
    public function test_value_or_without_arguments_throws_on_a_failure(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('valueOr() was called on a failed Result');

        Result::notFound('No note.')->valueOr();
    }

    /**
     * The other half of the same distinction: an explicit null is a real default,
     * and a caller who asked for it must get it rather than an exception.
     */
    public function test_value_or_with_an_explicit_null_returns_null_on_a_failure(): void
    {
        $this->assertNull(Result::notFound()->valueOr(null));
    }

    public function test_value_or_returns_the_supplied_default_on_a_failure(): void
    {
        $this->assertSame('fallback', Result::forbidden()->valueOr('fallback'));
    }

    public function test_value_or_returns_the_value_on_success(): void
    {
        $this->assertSame(['id' => 1], Result::ok(['id' => 1])->valueOr('fallback'));
    }

    /**
     * A success whose value is null is still a success, so the default is not
     * consulted — the value is null because the use case returned null.
     */
    public function test_value_or_does_not_substitute_for_a_null_success_value(): void
    {
        $this->assertNull(Result::ok(null)->valueOr('fallback'));
    }

    /**
     * The falsy-value trap: a default that replaces `0` or `''` would make it
     * impossible to return a legitimate zero from a use case.
     *
     * @param mixed $value
     *
     * @dataProvider falsySuccessValues
     */
    public function test_value_or_keeps_a_falsy_success_value(mixed $value): void
    {
        $this->assertSame($value, Result::ok($value)->valueOr('fallback'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function falsySuccessValues(): array
    {
        return [
            'zero' => [0],
            'empty string' => [''],
            'false' => [false],
            'empty array' => [[]],
        ];
    }

    // ------------------------------------------------------------- error payload

    public function test_the_error_payload_carries_the_status_and_field_errors(): void
    {
        $payload = Result::validation(['title' => ['Required.']])->toErrorPayload();

        $this->assertIsArray($payload);
        $this->assertSame('validation_failed', $payload['code']);
        $this->assertSame(422, $payload['data']['status']);
        $this->assertSame(['title' => ['Required.']], $payload['data']['errors']);
    }

    /**
     * A failure built by hand has no code of its own, and a transport still needs
     * something to switch on. The generic code is what keeps the payload's shape
     * stable rather than nullable in a place callers do not expect.
     */
    public function test_the_error_payload_falls_back_to_a_generic_code(): void
    {
        $payload = Result::fail('Something failed.')->toErrorPayload();

        $this->assertIsArray($payload);
        $this->assertSame('result_failed', $payload['code']);
        $this->assertSame('Something failed.', $payload['message']);
    }

    /**
     * The message is the rewordable part; the code is what a client switches on.
     * This asserts the payload keeps them apart rather than collapsing the code
     * into the message text.
     */
    public function test_the_error_payload_keeps_the_code_apart_from_the_message(): void
    {
        $payload = Result::notFound('No note with that id.')->toErrorPayload();

        $this->assertIsArray($payload);
        $this->assertSame('not_found', $payload['code']);
        $this->assertSame('No note with that id.', $payload['message']);
        $this->assertNotSame($payload['code'], $payload['message']);
    }
}
