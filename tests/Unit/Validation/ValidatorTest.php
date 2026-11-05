<?php
declare(strict_types=1);

namespace Tests\Unit\Validation;

use Framework\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The rule engine, on its own.
 *
 * Every test here builds data and calls `validate()` directly — no request, no
 * container, no WordPress. That is the property the class was designed for: if
 * any test in this file needed a `Request` or a stub to pass, the validator would
 * have picked up a dependency it is not supposed to have.
 */
final class ValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed>  $data
     * @param array<string, mixed>  $rules
     * @return array<string, list<string>>
     */
    private function errors(array $data, array $rules): array
    {
        return (new Validator($data))->validate($rules);
    }

    // ------------------------------------------------------------------ required

    public function test_required_rejects_an_absent_field(): void
    {
        $this->assertArrayHasKey('name', $this->errors([], ['name' => 'required']));
    }

    public function test_required_rejects_an_empty_string(): void
    {
        $this->assertArrayHasKey('name', $this->errors(['name' => ''], ['name' => 'required']));
    }

    public function test_required_rejects_a_whitespace_only_string(): void
    {
        $this->assertArrayHasKey('name', $this->errors(['name' => '   '], ['name' => 'required']));
    }

    public function test_required_rejects_an_empty_array(): void
    {
        $this->assertArrayHasKey('tags', $this->errors(['tags' => []], ['tags' => 'required']));
    }

    /**
     * The classic false-y-value bug: a rule that treats "0" as missing makes it
     * impossible to save a record whose quantity is zero.
     */
    public function test_required_accepts_the_string_zero(): void
    {
        $this->assertSame([], $this->errors(['qty' => '0'], ['qty' => 'required']));
    }

    public function test_required_accepts_false(): void
    {
        $this->assertSame([], $this->errors(['flag' => false], ['flag' => 'required']));
    }

    public function test_required_accepts_the_integer_zero(): void
    {
        $this->assertSame([], $this->errors(['qty' => 0], ['qty' => 'required']));
    }

    // ------------------------------------------------------------------ optional

    public function test_an_absent_field_with_no_required_rule_is_silent(): void
    {
        $this->assertSame([], $this->errors([], ['nickname' => 'string|max:10']));
    }

    public function test_nullable_accepts_an_explicit_null(): void
    {
        $this->assertSame([], $this->errors(['bio' => null], ['bio' => 'nullable|string']));
    }

    public function test_nullable_does_not_rescue_a_required_field(): void
    {
        $this->assertArrayHasKey('x', $this->errors(['x' => null], ['x' => 'required|nullable']));
    }

    public function test_sometimes_skips_an_absent_field(): void
    {
        $this->assertSame([], $this->errors([], ['title' => 'sometimes|required|string']));
    }

    public function test_sometimes_still_validates_a_present_field(): void
    {
        $this->assertArrayHasKey('title', $this->errors(['title' => ''], ['title' => 'sometimes|required']));
    }

    // --------------------------------------------------------------------- types

    public function test_string_rejects_an_array(): void
    {
        $this->assertArrayHasKey('name', $this->errors(['name' => ['a']], ['name' => 'string']));
    }

    public function test_integer_accepts_a_digit_string(): void
    {
        $this->assertSame([], $this->errors(['age' => '30'], ['age' => 'integer']));
    }

    public function test_integer_rejects_a_decimal_string(): void
    {
        $this->assertArrayHasKey('age', $this->errors(['age' => '30.5'], ['age' => 'integer']));
    }

    public function test_numeric_accepts_a_decimal(): void
    {
        $this->assertSame([], $this->errors(['price' => '9.99'], ['price' => 'numeric']));
    }

    public function test_boolean_accepts_the_string_false(): void
    {
        $this->assertSame([], $this->errors(['p' => 'false'], ['p' => 'boolean']));
    }

    public function test_boolean_rejects_an_arbitrary_string(): void
    {
        $this->assertArrayHasKey('p', $this->errors(['p' => 'maybe'], ['p' => 'boolean']));
    }

    public function test_email_rejects_a_malformed_address(): void
    {
        $this->assertArrayHasKey('email', $this->errors(['email' => 'not-an-email'], ['email' => 'email']));
    }

    public function test_email_accepts_a_plain_address(): void
    {
        $this->assertSame([], $this->errors(['email' => 'a@b.test'], ['email' => 'email']));
    }

    public function test_url_rejects_a_bare_word(): void
    {
        $this->assertArrayHasKey('site', $this->errors(['site' => 'example'], ['site' => 'url']));
    }

    public function test_date_rejects_a_non_date(): void
    {
        $this->assertArrayHasKey('when', $this->errors(['when' => 'not a date'], ['when' => 'date']));
    }

    public function test_array_rejects_a_string(): void
    {
        $this->assertArrayHasKey('tags', $this->errors(['tags' => 'x'], ['tags' => 'array']));
    }

    // ------------------------------------------------------------- value lists

    public function test_in_accepts_a_listed_value(): void
    {
        $this->assertSame([], $this->errors(['role' => 'editor'], ['role' => 'in:admin,editor']));
    }

    public function test_in_rejects_an_unlisted_value(): void
    {
        $this->assertArrayHasKey('role', $this->errors(['role' => 'root'], ['role' => 'in:admin,editor']));
    }

    /**
     * A checkbox group posts an array, and every member has to be allowed — one
     * bad member is a rejected submission, not a silently filtered one.
     */
    public function test_in_rejects_an_array_with_one_bad_member(): void
    {
        $this->assertArrayHasKey(
            'roles',
            $this->errors(['roles' => ['admin', 'root']], ['roles' => 'in:admin,editor'])
        );
    }

    public function test_in_accepts_an_array_of_allowed_members(): void
    {
        $this->assertSame(
            [],
            $this->errors(['roles' => ['admin', 'editor']], ['roles' => 'in:admin,editor'])
        );
    }

    public function test_not_in_rejects_a_listed_value(): void
    {
        $this->assertArrayHasKey('name', $this->errors(['name' => 'admin'], ['name' => 'not_in:admin']));
    }

    // ----------------------------------------------------------- size and bounds

    public function test_min_on_a_numeric_string_compares_numerically(): void
    {
        $this->assertArrayHasKey('age', $this->errors(['age' => '9'], ['age' => 'integer|min:18']));
    }

    public function test_min_accepts_a_numeric_string_above_the_bound(): void
    {
        $this->assertSame([], $this->errors(['age' => '30'], ['age' => 'integer|min:18']));
    }

    public function test_max_on_a_non_numeric_string_measures_length(): void
    {
        $this->assertArrayHasKey('name', $this->errors(['name' => 'abcdef'], ['name' => 'string|max:3']));
    }

    public function test_max_accepts_a_non_numeric_string_within_length(): void
    {
        $this->assertSame([], $this->errors(['name' => 'abc'], ['name' => 'string|max:3']));
    }

    public function test_max_counts_array_elements(): void
    {
        $this->assertArrayHasKey('tags', $this->errors(['tags' => ['a', 'b', 'c']], ['tags' => 'array|max:2']));
    }

    /**
     * One out-of-range value is one mistake, so it produces one message. Two
     * would read as two separate problems and send the user looking for a second.
     */
    public function test_between_produces_a_single_message(): void
    {
        $errors = $this->errors(['age' => '200'], ['age' => 'between:18,120']);

        $this->assertCount(1, $errors['age']);
    }

    public function test_between_accepts_a_value_inside_the_range(): void
    {
        $this->assertSame([], $this->errors(['age' => '30'], ['age' => 'between:18,120']));
    }

    public function test_between_rejects_a_value_in_the_middle_of_a_bad_range(): void
    {
        $this->assertSame([], $this->errors(['age' => '50'], ['age' => 'between:18,120']));
    }

    public function test_size_accepts_an_exact_string_length(): void
    {
        $this->assertSame([], $this->errors(['code' => 'abcde'], ['code' => 'size:5']));
    }

    public function test_size_rejects_a_wrong_string_length(): void
    {
        $this->assertArrayHasKey('code', $this->errors(['code' => 'abcd'], ['code' => 'size:5']));
    }

    // ------------------------------------------------------------------- parsing

    /**
     * A pipe inside a regex is content, not a separator. A naive `explode('|')`
     * tears the pattern in half and reports an unknown rule named after the
     * fragment — an error pointing at the wrong thing entirely.
     */
    public function test_a_pipe_inside_a_regex_argument_is_not_a_rule_separator(): void
    {
        $this->assertSame(
            [],
            $this->errors(['code' => 'a|b'], ['code' => 'regex:/^[a-c]\|[a-c]$/'])
        );
    }

    public function test_a_comma_inside_a_regex_quantifier_is_not_an_argument_separator(): void
    {
        $this->assertArrayHasKey('code', $this->errors(['code' => 'abcd'], ['code' => 'regex:/^[a-z]{1,3}$/']));
    }

    public function test_a_regex_with_trailing_modifiers_still_closes(): void
    {
        $this->assertSame([], $this->errors(['code' => 'AB'], ['code' => 'regex:/^[a-z]{1,3}$/i']));
    }

    public function test_rules_may_be_given_as_an_array(): void
    {
        $this->assertArrayHasKey('name', $this->errors(['name' => ''], ['name' => ['required', 'string']]));
    }

    public function test_an_empty_rule_string_validates_nothing(): void
    {
        $this->assertSame([], $this->errors(['x' => 'anything'], ['x' => '']));
    }

    public function test_a_pipe_string_and_an_array_are_equivalent(): void
    {
        $string = $this->errors(['name' => ''], ['name' => 'required|string|max:3']);
        $array = $this->errors(['name' => ''], ['name' => ['required', 'string', 'max:3']]);

        $this->assertSame($string, $array);
    }

    // ------------------------------------------------------------------ wildcards

    public function test_a_wildcard_validates_every_element(): void
    {
        $errors = $this->errors(
            ['tags' => ['ok', 'toolong']],
            ['tags.*' => 'string|max:5']
        );

        $this->assertArrayHasKey('tags.1', $errors);
        $this->assertArrayNotHasKey('tags.0', $errors);
    }

    /**
     * The message has to name the field, not the array index: "tags.1 must be a
     * string" is a message about the implementation, not about the user's input.
     */
    public function test_a_wildcard_message_names_the_field_not_the_index(): void
    {
        $errors = $this->errors(['tags' => ['ok', 'toolong']], ['tags.*' => 'string|max:5']);

        $this->assertStringContainsString('tags field', $errors['tags.1'][0]);
    }

    public function test_a_wildcard_over_an_absent_field_is_silent(): void
    {
        $this->assertSame([], $this->errors([], ['tags.*' => 'string']));
    }

    public function test_a_wildcard_over_a_non_array_is_silent(): void
    {
        // The parent field's own `array` rule is what reports this; a per-element
        // message would name a key that does not exist.
        $this->assertSame([], $this->errors(['tags' => 'x'], ['tags.*' => 'string']));
    }

    public function test_a_wildcard_validates_every_element_when_all_are_good(): void
    {
        $this->assertSame(
            [],
            $this->errors(['tags' => ['a', 'bb', 'ccc']], ['tags' => 'array', 'tags.*' => 'string|max:5'])
        );
    }

    // -------------------------------------------------------------- comparisons

    public function test_confirmed_requires_a_matching_confirmation(): void
    {
        $this->assertArrayHasKey(
            'password',
            $this->errors(['password' => 'a', 'password_confirmation' => 'b'], ['password' => 'confirmed'])
        );
    }

    public function test_confirmed_passes_on_a_match(): void
    {
        $this->assertSame(
            [],
            $this->errors(['password' => 'a', 'password_confirmation' => 'a'], ['password' => 'confirmed'])
        );
    }

    public function test_same_compares_against_another_field(): void
    {
        $this->assertSame([], $this->errors(['a' => '1', 'b' => '1'], ['a' => 'same:b']));
    }

    public function test_different_rejects_equal_values(): void
    {
        $this->assertArrayHasKey('a', $this->errors(['a' => '1', 'b' => '1'], ['a' => 'different:b']));
    }

    /**
     * A field that was not sent cannot conflict with anything, so `different`
     * must not make an optional field impossible to leave blank.
     */
    public function test_different_passes_when_the_other_field_is_absent(): void
    {
        $this->assertSame([], $this->errors(['a' => '1'], ['a' => 'sometimes|different:b']));
    }

    // ------------------------------------------------------------------ closures

    public function test_a_closure_that_calls_fail_reports_its_message(): void
    {
        $errors = $this->errors(['n' => 5], [
            'n' => function (string $attribute, mixed $value, callable $fail): void {
                if ($value !== 7) {
                    $fail('n must be seven');
                }
            },
        ]);

        $this->assertSame('n must be seven', $errors['n'][0]);
    }

    public function test_a_closure_returning_false_fails(): void
    {
        $errors = $this->errors(['n' => 5], ['n' => static fn (string $a, mixed $v): bool => $v === 7]);

        $this->assertArrayHasKey('n', $errors);
    }

    public function test_a_closure_returning_true_passes(): void
    {
        $this->assertSame(
            [],
            $this->errors(['n' => 7], ['n' => static fn (string $a, mixed $v): bool => $v === 7])
        );
    }

    /**
     * A two-parameter closure must not be handed the reporter — a third argument
     * would be a fatal. Reflection decides, which is what this pins.
     */
    public function test_a_two_parameter_closure_is_not_passed_a_reporter(): void
    {
        $this->assertSame(
            [],
            $this->errors(['n' => 'ok'], ['n' => static fn (string $a, mixed $v): bool => $v === 'ok'])
        );
    }

    public function test_a_closure_may_be_mixed_with_string_rules(): void
    {
        $errors = $this->errors(['n' => 'ab'], [
            'n' => ['required', static fn (string $a, mixed $v): bool => $v !== 'ab', 'max:5'],
        ]);

        $this->assertArrayHasKey('n', $errors);
    }

    // --------------------------------------------------------- author's mistakes

    /**
     * A typo'd rule name must not silently accept everything — the whole value of
     * a validator is lost if a misspelled rule passes.
     */
    public function test_an_unknown_rule_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requird/');

        $this->errors(['x' => 'y'], ['x' => 'requird']);
    }

    public function test_a_malformed_regex_pattern_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->errors(['x' => 'y'], ['x' => 'regex:/[/']);
    }

    public function test_min_without_a_bound_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->errors(['x' => 'y'], ['x' => 'min']);
    }

    public function test_in_without_allowed_values_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->errors(['x' => 'y'], ['x' => 'in']);
    }

    // -------------------------------------------------------------------- result

    public function test_every_failing_rule_on_one_field_is_reported(): void
    {
        $errors = $this->errors(['email' => ''], ['email' => 'required|email']);

        $this->assertCount(2, $errors['email']);
    }

    public function test_messages_flattens_every_error(): void
    {
        $validator = new Validator(['a' => '', 'b' => '']);
        $validator->validate(['a' => 'required', 'b' => 'required']);

        $this->assertCount(2, $validator->messages());
    }

    public function test_first_returns_a_single_message(): void
    {
        $validator = new Validator(['email' => '']);
        $validator->validate(['email' => 'required|email']);

        $this->assertIsString($validator->first('email'));
    }

    public function test_first_is_null_for_a_field_that_passed(): void
    {
        $validator = new Validator(['a' => 'b']);
        $validator->validate(['a' => 'required']);

        $this->assertNull($validator->first('a'));
    }

    public function test_passes_and_fails_agree(): void
    {
        $good = new Validator(['a' => 'b']);
        $good->validate(['a' => 'required']);

        $bad = new Validator(['a' => '']);
        $bad->validate(['a' => 'required']);

        $this->assertTrue($good->passes());
        $this->assertFalse($good->fails());
        $this->assertTrue($bad->fails());
        $this->assertFalse($bad->passes());
    }

    public function test_errors_reset_between_runs(): void
    {
        $validator = new Validator(['a' => '']);

        $validator->validate(['a' => 'required']);
        $this->assertTrue($validator->fails());

        // A second run with a rule set that passes must clear the first run's
        // errors. Without the reset, an instance reused across fields — which is
        // how a loop of independent validations would use it — would accumulate
        // every failure it had ever seen.
        $validator->validate(['a' => 'sometimes', 'b' => 'sometimes']);
        $this->assertTrue($validator->passes(), 'A second run must not inherit the first run\'s errors.');
        $this->assertSame([], $validator->errors());
    }

    public function test_a_label_override_appears_in_the_message(): void
    {
        $validator = new Validator(['email' => ''], ['email' => 'email address']);
        $errors = $validator->validate(['email' => 'required']);

        $this->assertStringContainsString('email address', $errors['email'][0]);
    }

    // ------------------------------------------------------------- dotted paths

    public function test_a_dotted_path_reads_a_nested_value(): void
    {
        $this->assertSame(
            [],
            $this->errors(['user' => ['name' => 'Ada']], ['user.name' => 'required|string'])
        );
    }

    public function test_a_dotted_path_reports_against_the_full_path(): void
    {
        $this->assertArrayHasKey(
            'user.name',
            $this->errors(['user' => ['name' => '']], ['user.name' => 'required'])
        );
    }
}
