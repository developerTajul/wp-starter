<?php
declare(strict_types=1);

/**
 * Manual probe for Framework\Validation\Validator.
 *
 * Not a PHPUnit test — a runnable script for the cases that are worth *seeing*
 * while the rule engine is being written. The real assertions live in
 * tests/Unit/Validation/ValidatorTest.php.
 *
 *     php tests/manual/probe-validator.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use Framework\Validation\Validator;

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;

    if (!$condition) {
        $failures++;
    }

    echo ($condition ? "  ok   " : "  FAIL ") . $label . "\n";
}

/** @param array<string, mixed> $data */
function errors(array $data, array $rules): array
{
    return (new Validator($data))->validate($rules);
}

echo "required / types\n";
check('valid data passes', errors(
    ['email' => 'a@b.test', 'age' => '30', 'role' => 'admin'],
    ['email' => 'required|email|max:255', 'age' => 'required|integer|between:18,120', 'role' => 'in:admin,editor']
) === []);

check('empty email produces both messages', count(errors(['email' => ''], ['email' => 'required|email'])['email'] ?? []) === 2);
check('absent optional field is silent', errors([], ['nickname' => 'string|max:10']) === []);
check('nullable accepts explicit null', errors(['bio' => null], ['bio' => 'nullable|string|max:10']) === []);
check('sometimes skips an absent field', errors([], ['title' => 'sometimes|required|string']) === []);
check('required accepts "0"', errors(['p' => '0'], ['p' => 'required|boolean']) === []);
check('required accepts false', errors(['p' => false], ['p' => 'required|boolean']) === []);
check('required rejects an empty array', isset(errors(['t' => []], ['t' => 'required|array'])['t']));
check('required|null still requires', isset(errors(['x' => null], ['x' => 'required|nullable'])['x']));

echo "\nwildcards\n";
$wild = errors(['tags' => ['a', 'b', 'toolong']], ['tags' => 'array', 'tags.*' => 'string|max:3']);
check('one bad element is reported', isset($wild['tags.2']) && count($wild) === 1);
check('message names the field, not the index', str_contains($wild['tags.2'][0] ?? '', 'tags field'));
check('wildcard over a non-array is silent', errors(['tags' => 'x'], ['tags.*' => 'string']) === []);

echo "\nrule-string parsing\n";
check('regex with an embedded pipe survives', errors(['c' => 'a|b'], ['c' => 'regex:/^[a-c]\|[a-c]$/']) === []);
check('regex with {1,3} is not truncated at the comma', isset(errors(['c' => 'abcd'], ['c' => 'regex:/^[a-z]{1,3}$/'])['c']));
check('regex with a trailing modifier still closes', errors(['c' => 'ab'], ['c' => 'regex:/^[a-z]{1,3}$/i']) === []);
check('empty rule string adds nothing', errors(['c' => 'x'], ['c' => '']) === []);

echo "\nmin / max / between / size\n";
check('min:18 rejects the string "9"', isset(errors(['a' => '9'], ['a' => 'integer|min:18'])['a']));
check('min:18 accepts the string "30"', errors(['a' => '30'], ['a' => 'integer|min:18']) === []);
check('max:3 on a string is a length check', isset(errors(['n' => 'abcdef'], ['n' => 'string|max:3'])['n']));
check('max counts array elements', errors(['t' => ['a', 'b']], ['t' => 'array|max:2']) === []);
$between = errors(['a' => '200'], ['a' => 'between:18,120']);
check('between reports one message, not two', count($between['a'] ?? []) === 1);
check('size:5 accepts "abcde"', errors(['c' => 'abcde'], ['c' => 'size:5']) === []);
check('size:5 rejects "abcd"', isset(errors(['c' => 'abcd'], ['c' => 'size:5'])['c']));

echo "\ncomparisons\n";
check('confirmed mismatch fails', isset(errors(['pw' => 'x', 'pw_confirmation' => 'y'], ['pw' => 'confirmed'])['pw']));
check('confirmed match passes', errors(['pw' => 'x', 'pw_confirmation' => 'x'], ['pw' => 'confirmed']) === []);
check('same compares against another field', errors(['a' => '1', 'b' => '1'], ['a' => 'same:b']) === []);
check('different rejects equal values', isset(errors(['a' => '1', 'b' => '1'], ['a' => 'different:b'])['a']));
check('different passes when the other is absent', errors(['a' => '1'], ['a' => 'sometimes|different:b']) === []);
check('in: rejects a bad array member', isset(errors(['r' => ['admin', 'root']], ['r' => 'in:admin,editor'])['r']));

echo "\nclosures\n";
$custom = errors(['n' => 5], ['n' => function (string $a, mixed $v, callable $fail): void {
    if ($v !== 7) {
        $fail('n must be seven');
    }
}]);
check('a $fail closure reports its own message', ($custom['n'][0] ?? '') === 'n must be seven');
check('a boolean-returning closure fails on false', isset(errors(['n' => 5], ['n' => fn (string $a, mixed $v): bool => $v === 7])['n']));
check('a boolean-returning closure passes on true', errors(['n' => 7], ['n' => fn (string $a, mixed $v): bool => $v === 7]) === []);
check('a two-parameter $fail-less closure is not passed a third arg', errors(
    ['n' => 'ok'],
    ['n' => static fn (string $a, mixed $v): bool => $v === 'ok']
) === []);

echo "\nmistakes the author made\n";
try {
    errors(['x' => 'y'], ['x' => 'requird']);
    check('an unknown rule throws', false);
} catch (InvalidArgumentException $e) {
    check('an unknown rule throws and names it', str_contains($e->getMessage(), 'requird'));
}

try {
    errors(['x' => 'y'], ['x' => 'regex:/[/']);
    check('a malformed pattern throws', false);
} catch (InvalidArgumentException $e) {
    check('a malformed pattern throws', true);
}

echo "\nresult accessors\n";
$v = new Validator(['email' => '']);
$v->validate(['email' => 'required|email']);
check('first() returns a single string', is_string($v->first('email')));
check('messages() flattens every error', count($v->messages()) === 2);
check('fails() is true', $v->fails());
check('passes() is false', !$v->passes());
check('first() on a valid field is null', (new Validator(['a' => 'b']))->first('a') === null);

echo "\n";
echo $failures === 0 ? "all probes passed\n" : "{$failures} probe(s) FAILED\n";

exit($failures === 0 ? 0 : 1);
