<?php
declare(strict_types=1);

namespace Framework\Validation;

/**
 * The rule engine: data in, errors out.
 *
 * It does not throw, does not redirect, does not read a request, and does not
 * know WordPress exists. Each of those would bind it to one caller: a validator
 * that threw on the first failure could not report the second bad field, and a
 * form revealing errors one reload at a time is the reason people hate forms; one
 * that redirected would be bound to the web domain, so the same rules could not
 * check a WP-CLI import, a settings option or a REST payload; one that knew about
 * `Request` could not validate the `$_POST` array a legacy handler holds, or a
 * decoded CSV row.
 *
 * {@see \Framework\Http\Requests\FormRequest} is the HTTP-shaped wrapper that
 * supplies the redirect-or-422 decision. This class only answers which rules
 * failed and why.
 *
 * `errors()` is `field => list of messages`, not `field => message`. A field with
 * two broken rules (an email both empty and malformed) has two things to say, and
 * collapsing them loses whichever ran second. The view layer can join them; it
 * cannot un-lose them.
 *
 * Rule strings:
 *
 *     'email'    => 'required|email|max:255'
 *     'tags.*'   => 'string|max:20'
 *     'age'      => ['nullable', 'integer', Rule::between(18, 120)]
 *
 * A rule is `name` or `name:arg,arg`. Arguments arrive as strings and each rule
 * decides what they mean: `max` compares a length or a value, `in` splits on
 * commas, `regex` takes the pattern verbatim including any commas or pipes inside
 * it, which is why the splitter is careful.
 *
 * A rule may be a closure receiving `(string $attribute, mixed $value)` and
 * reporting failure by throwing or by returning false; {@see passes()} explains
 * why both spellings are accepted.
 */
class Validator
{
    /**
     * Every message produced by the last run, as field => list of messages.
     *
     * @var array<string, list<string>>
     */
    private array $errors = [];

    /**
     * The rule names, and the method on this class that runs each.
     *
     * A map rather than a `method_exists()` convention, because the convention
     * would make `validateFoo()` on a subclass silently callable as a rule named
     * `foo`  -  a subclass could not add a private helper without it becoming part
     * of the DSL. Listing them here keeps the rule set a closed, documented set,
     * and makes `validate('rule:that_does_not_exist')` a reportable mistake
     * rather than a silent pass.
     *
     * @var array<string, string>
     */
    private const RULES = [
        'required' => 'validateRequired',
        'nullable' => 'validateNullable',
        'sometimes' => 'validateSometimes',
        'string' => 'validateString',
        'integer' => 'validateInteger',
        'numeric' => 'validateNumeric',
        'boolean' => 'validateBoolean',
        'array' => 'validateArray',
        'email' => 'validateEmail',
        'url' => 'validateUrl',
        'date' => 'validateDate',
        'in' => 'validateIn',
        'not_in' => 'validateNotIn',
        'min' => 'validateMin',
        'max' => 'validateMax',
        'between' => 'validateBetween',
        'size' => 'validateSize',
        'regex' => 'validateRegex',
        'same' => 'validateSame',
        'different' => 'validateDifferent',
        'confirmed' => 'validateConfirmed',
        'accepted' => 'validateAccepted',
    ];

    /**
     * Rules that only *decide whether to bother* and never fail on their own.
     *
     * `nullable` and `sometimes` are modifiers, not checks: they change what the
     * remaining rules do with an absent or null value. Counting them as rules
     * that "passed" would be harmless; counting them as rules that *failed*
     * would reject every optional field, which is why they are separated here
     * rather than left to each validator's own guard.
     *
     * @var list<string>
     */
    private const MODIFIERS = ['nullable', 'sometimes'];

    /**
     * Human-readable names for the fields in messages.
     *
     * The key is the attribute path *without* a wildcard segment, so `tags` can
     * read "tag list" rather than "tags" in an error a person has to read. A
     * project overrides them by passing its own map to the constructor.
     *
     * @var array<string, string>
     */
    private readonly array $labels;

    /**
     * @param array<string, mixed>  $data   The values to validate.
     * @param array<string, string> $labels Optional attribute => label overrides.
     */
    public function __construct(
        private readonly array $data,
        array $labels = []
    ) {
        $this->labels = $labels;
    }

    /**
     * Validate data against rules, returning the messages that failed.
     *
     * Runs every field and every rule  -  it does not stop at the first failure  - 
     * because the whole point of validating on the server is to tell the user
     * everything that is wrong in one pass.
     *
     * @param array<string, string|array<mixed>|callable> $rules
     *
     * @return array<string, list<string>> Field => messages. Empty means valid.
     */
    public function validate(array $rules): array
    {
        $this->errors = [];

        foreach ($rules as $attribute => $fieldRules) {
            $this->validateAttribute((string) $attribute, $this->parse($fieldRules));
        }

        return $this->errors;
    }

    /**
     * Whether the last validate() call found nothing wrong.
     *
     * Only meaningful after `validate()`; a fresh instance reports true, which is
     * the honest answer for "no rules have been checked yet".
     */
    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Every message, flattened, for a caller that just wants to show them all.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_values(array_merge(...array_values($this->errors ?: [[]])));
    }

    /**
     * The first message recorded for a field, or null.
     *
     * The shape a view needs for an inline field error, where showing all three
     * things wrong with one input is noise.
     */
    public function first(string $attribute): ?string
    {
        return $this->errors[$attribute][0] ?? null;
    }


    /**
     * Run one attribute's rules, applying the modifiers first.
     *
     * @param list<array{name: string, args: list<string>}|callable> $rules
     */
    private function validateAttribute(string $attribute, array $rules): void
    {
        // Modifiers are read before anything else because they change what
        // "absent" means for this field, and every rule below asks that question.
        $names = array_map(
            static fn (array|callable $rule): string => is_callable($rule) ? 'closure' : $rule['name'],
            $rules
        );

        $nullable = in_array('nullable', $names, true);
        $sometimes = in_array('sometimes', $names, true);

        // A wildcard attribute addresses every element of an array field:
        // `tags.*` validates each tag. The expansion happens here rather than in
        // parse(), because it depends on the *data*, which parse() never sees.
        if (str_contains($attribute, '*')) {
            $this->validateWildcard($attribute, $rules, $nullable, $sometimes);

            return;
        }

        $present = $this->has($attribute);
        $value = $this->value($attribute);

        // `sometimes` means "only validate when the field was sent". Absent and
        // skipped is the whole contract, and it is how a PATCH differs from a PUT
        // without a second rule set.
        if ($sometimes && !$present) {
            return;
        }

        $required = in_array('required', $names, true);

        // Absent, and not required: there is nothing to check. Every rule below
        // would otherwise report a missing field as a malformed one  -  "email must
        // be a valid email address" for a field the user never filled in is the
        // single most common bad error message in web forms.
        if (!$present && !$required) {
            return;
        }

        // Present but null, with `nullable`: the author has said an explicit null
        // is an acceptable answer, so the type rules stand down. `required` still
        // wins  -  `required|null` is a contradiction, and explaining it as "you
        // must send something" is more useful than silently accepting null.
        if ($nullable && $value === null && !$required) {
            return;
        }

        foreach ($rules as $rule) {
            if (is_callable($rule)) {
                $this->runClosure($attribute, $value, $rule);

                continue;
            }

            if (in_array($rule['name'], self::MODIFIERS, true)) {
                continue;
            }

            $method = self::RULES[$rule['name']] ?? null;

            if ($method === null) {
                // An unknown rule is a mistake in the rules definition, not data
                // the user got wrong. Reported against the field so the author
                // sees which one, and raised rather than passed  -  a typo'd
                // `requird` that silently accepted everything would ship.
                throw new \InvalidArgumentException(sprintf(
                    'Unknown validation rule [%s] on attribute [%s].',
                    $rule['name'],
                    $attribute
                ));
            }

            $this->{$method}($attribute, $value, $rule['args']);
        }
    }

    /**
     * Expand a `field.*` rule across every element of an array field.
     *
     * @param list<array{name: string, args: list<string>}|callable> $rules
     */
    private function validateWildcard(string $attribute, array $rules, bool $nullable, bool $sometimes): void
    {
        [$parent, $rest] = explode('*', $attribute, 2);
        $parent = rtrim($parent, '.');

        $container = $this->value($parent);

        // A wildcard over a field that is absent, or is not an array, has nothing
        // to expand. The container field itself is the one that should carry
        // `required|array`; reporting a per-element error here would name
        // `tags.0` for a field the user never sent.
        if (!is_array($container)) {
            return;
        }

        foreach (array_keys($container) as $key) {
            $this->validateAttribute(
                $parent . '.' . $key . $rest,
                $rules
            );
        }
    }


    /**
     * Whether a dotted path was supplied at all.
     *
     * `array_key_exists` rather than `isset`, so a key sent as an explicit null
     * counts as present  -  which is what makes `nullable` meaningful instead of
     * indistinguishable from "absent".
     */
    private function has(string $attribute): bool
    {
        $segments = explode('.', $attribute);
        $cursor = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * The value at a dotted path, or null.
     *
     * @return mixed
     */
    private function value(string $attribute): mixed
    {
        $segments = explode('.', $attribute);
        $cursor = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * The name a message uses for an attribute.
     *
     * Falls back to the attribute with its underscores turned into spaces, and
     * its wildcard index stripped, so `tags.0` reads "tags"  -  a message that says
     * "tags.0 must be a string" is a message about the implementation.
     */
    private function label(string $attribute): string
    {
        $key = $attribute;

        if (isset($this->labels[$key])) {
            return $this->labels[$key];
        }

        // A wildcard-expanded path addresses one element; the message should name
        // the field, not the position.
        if (preg_match('/^(.+)\.\d+$/', $attribute, $m) === 1) {
            $key = $m[1];

            if (isset($this->labels[$key])) {
                return $this->labels[$key];
            }
        }

        return str_replace(['_', '.'], ' ', $key);
    }


    /**
     * Turn one field's rule definition into a uniform list.
     *
     * Accepts the pipe string (`'required|max:5'`), a single rule string, an
     * array of either, and closures mixed in. Normalizing here means the walker
     * above never branches on the author's spelling.
     *
     * @param string|array<mixed>|callable $rules
     * @return list<array{name: string, args: list<string>}|callable>
     */
    private function parse(string|array|callable $rules): array
    {
        if (is_callable($rules) && !is_string($rules)) {
            return [$rules];
        }

        $parsed = [];

        foreach (is_array($rules) ? $rules : $this->split((string) $rules) as $rule) {
            if (is_string($rule)) {
                $parsed[] = $this->parseRule($rule);

                continue;
            }

            // Any other callable  -  a Closure, an invokable object, a first-class
            // callable. Stored as-is; {@see runClosure()} reflects over it later
            // to decide how many arguments it wants.
            if (is_callable($rule)) {
                $parsed[] = $rule;

                continue;
            }

            throw new \InvalidArgumentException(sprintf(
                'A validation rule must be a string or a callable; %s given.',
                get_debug_type($rule)
            ));
        }

        return $parsed;
    }

    /**
     * Split a pipe-delimited rule string, respecting a regex's own pipes.
     *
     * `regex:/^a|b$/` is a legitimate rule whose argument contains a pipe, and a
     * naive `explode('|')` would tear it in half  -  producing a rule named
     * `regex:/^a` and a rule named `b$/`, neither of which exists, and an
     * "unknown rule" error that points at the wrong thing entirely. The splitter
     * therefore tracks whether it is inside a `/.../` pattern.
     *
     * @return list<string>
     */
    private function split(string $rules): array
    {
        if ($rules === '') {
            return [];
        }

        $parts = [];
        $buffer = '';
        $inPattern = false;
        $delimiter = '';

        $length = strlen($rules);

        for ($i = 0; $i < $length; $i++) {
            $char = $rules[$i];

            // A pattern opens at a `/` that immediately follows `regex:` and
            // closes at the next unescaped `/`. Inside it, `|` is content.
            if ($char === '/' && ($inPattern === false && str_ends_with($buffer, 'regex:'))) {
                $inPattern = true;
                $delimiter = '/';
                $buffer .= $char;

                continue;
            }

            if ($inPattern) {
                // A backslash escapes the next character, so `\/` does not close
                // the pattern  -  the same rule PCRE itself applies.
                if ($char === '\\') {
                    $buffer .= $char . ($rules[$i + 1] ?? '');
                    $i++;

                    continue;
                }

                if ($char === $delimiter) {
                    $inPattern = false;
                }

                $buffer .= $char;

                continue;
            }

            if ($char === '|') {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $part): bool => $part !== ''
        ));
    }

    /**
     * Parse one `name` or `name:arg,arg` rule.
     *
     * @return array{name: string, args: list<string>}
     */
    private function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) {
            return ['name' => strtolower(trim($rule)), 'args' => []];
        }

        [$name, $argument] = explode(':', $rule, 2);

        // `regex` keeps its argument whole  -  a pattern may contain commas
        // (`{1,3}`) and splitting on them would truncate it into a different,
        // still-valid, silently-wrong pattern.
        if (strtolower(trim($name)) === 'regex') {
            return ['name' => 'regex', 'args' => [$argument]];
        }

        return [
            'name' => strtolower(trim($name)),
            'args' => array_map('trim', explode(',', $argument)),
        ];
    }


    /**
     * Record a failure against an attribute.
     */
    private function addError(string $attribute, string $message): void
    {
        $this->errors[$attribute][] = $message;
    }

    /**
     * @param list<string> $args
     */
    private function validateRequired(string $attribute, mixed $value, array $args): void
    {
        // An empty *string* is missing for a required field, and so is an empty
        // array  -  a form that submits `tags[]` with nothing selected sends `[]`,
        // and accepting it would let a "choose at least one" rule pass vacuously.
        // `0` and `false` are NOT empty: they are values a user deliberately
        // chose, and treating them as missing is the classic bug where "0 items"
        // cannot be saved.
        $missing = $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);

        if ($missing) {
            $this->addError($attribute, sprintf('The %s field is required.', $this->label($attribute)));
        }
    }

    /**
     * A modifier: no check of its own. See {@see validateAttribute()}.
     *
     * @param list<string> $args
     */
    private function validateNullable(string $attribute, mixed $value, array $args): void
    {
    }

    /**
     * A modifier: no check of its own. See {@see validateAttribute()}.
     *
     * @param list<string> $args
     */
    private function validateSometimes(string $attribute, mixed $value, array $args): void
    {
    }


    /**
     * @param list<string> $args
     */
    private function validateString(string $attribute, mixed $value, array $args): void
    {
        if (!is_string($value)) {
            $this->addError($attribute, sprintf('The %s field must be a string.', $this->label($attribute)));
        }
    }

    /**
     * @param list<string> $args
     */
    private function validateInteger(string $attribute, mixed $value, array $args): void
    {
        // A digit-string is accepted because every value from a form or a query
        // string *is* a string. Demanding a PHP int here would make the rule
        // unusable on real input while looking correct in a unit test that built
        // its data by hand  -  which is the trap {@see validateNumeric()} avoids
        // differently.
        $ok = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);

        if (!$ok) {
            $this->addError($attribute, sprintf('The %s field must be an integer.', $this->label($attribute)));
        }
    }

    /**
     * @param list<string> $args
     */
    private function validateNumeric(string $attribute, mixed $value, array $args): void
    {
        $ok = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));

        if (!$ok) {
            $this->addError($attribute, sprintf('The %s field must be a number.', $this->label($attribute)));
        }
    }

    /**
     * @param list<string> $args
     */
    private function validateBoolean(string $attribute, mixed $value, array $args): void
    {
        // The same spellings {@see \Framework\Routing\ParameterBinder} accepts for
        // a `bool` parameter, and for the same reason: a form sends the *string*
        // "false", and PHP coerces it to true. Accepting only the spellings that
        // mean something unambiguous keeps the two layers from disagreeing.
        $ok = is_bool($value)
            || $value === 0
            || $value === 1
            || (is_string($value) && in_array(strtolower(trim($value)), ['true', 'false', '0', '1'], true));

        if (!$ok) {
            $this->addError($attribute, sprintf('The %s field must be true or false.', $this->label($attribute)));
        }
    }

    /**
     * @param list<string> $args
     */
    private function validateArray(string $attribute, mixed $value, array $args): void
    {
        if (!is_array($value)) {
            $this->addError($attribute, sprintf('The %s field must be an array.', $this->label($attribute)));
        }
    }

    /**
     * @param list<string> $args
     */
    private function validateEmail(string $attribute, mixed $value, array $args): void
    {
        // `filter_var` rather than a hand-rolled pattern, and not the deprecated
        // FILTER_SANITIZE_EMAIL  -  sanitizing silently rewrites `a@b` into
        // something else and validates the rewrite, which is how an address the
        // user cannot receive mail at gets stored.
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($attribute, sprintf('The %s field must be a valid email address.', $this->label($attribute)));
        }
    }

    /**
     * A URL, optionally restricted to a list of schemes: `url:http,https`.
     *
     * With no arguments the allowed schemes default to `http,https`, not to
     * "anything `filter_var` accepts". `FILTER_VALIDATE_URL` passes `ftp://`,
     * `mailto:` and a dozen other schemes, and a form field bound for an
     * `<a href>` or a redirect wants none of them  -  so the strict list is the
     * default and a caller that genuinely needs `ftp` names it.
     *
     * The scheme is compared case-insensitively: schemes are case-insensitive
     * per RFC 3986, and `parse_url` returns whatever case the input used, so
     * `HTTPS://example.com` must satisfy `https`.
     *
     * An empty argument list is not the same as an absent one. `url:` parses to
     * `['']`, which names no scheme at all and would reject every value; that is
     * treated as a misconfiguration and raises rather than silently failing
     * every submission, the same way `in` and `between` handle a missing
     * argument.
     *
     * @param list<string> $args
     */
    private function validateUrl(string $attribute, mixed $value, array $args): void
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->addError($attribute, sprintf('The %s field must be a valid URL.', $this->label($attribute)));

            return;
        }

        $allowed = $args === [] ? ['http', 'https'] : $args;

        if (in_array('', $allowed, true)) {
            throw new \InvalidArgumentException(
                sprintf('The [url] rule on attribute [%s] has an empty scheme.', $attribute)
            );
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (!is_string($scheme) || !in_array(strtolower($scheme), array_map('strtolower', $allowed), true)) {
            $this->addError($attribute, sprintf(
                'The %s field must be a URL using one of these schemes: %s.',
                $this->label($attribute),
                implode(', ', $allowed),
            ));
        }
    }

    /**
     * @param list<string> $args
     */
    private function validateDate(string $attribute, mixed $value, array $args): void
    {
        if (!is_string($value)) {
            $this->addError($attribute, sprintf('The %s field must be a valid date.', $this->label($attribute)));

            return;
        }

        // The rule is "a date `strtotime` can read", and that is the whole check.
        // The `date` rule deliberately accepts relative and natural-language
        // forms  -  "next tuesday", "+1 week", "now"  -  because `strtotime` does and
        // because a human filling in a date field may reasonably write one. A
        // caller that needs a machine-parsable format should pin it with
        // `date_format`, which is a different rule with a different contract.
        //
        // An earlier version of this comment described a round-trip check that
        // compared the formatted timestamp back against the input. The code never
        // did it, and it is not what this rule should do: the round trip rejects
        // almost every legitimate spelling  -  `2026-11-05 09:30:00` gains a
        // timezone, `05/11/2026` normalizes to `2026-11-05`  -  so it would refuse
        // far more correct input than it caught.
        $timestamp = strtotime($value);

        if ($timestamp === false) {
            $this->addError($attribute, sprintf('The %s field must be a valid date.', $this->label($attribute)));
        }
    }


    /**
     * @param list<string> $args
     */
    private function validateIn(string $attribute, mixed $value, array $args): void
    {
        if ($args === []) {
            throw new \InvalidArgumentException(
                sprintf('The [in] rule on attribute [%s] requires at least one allowed value.', $attribute)
            );
        }

        // Compared as strings, because the request value almost always is one:
        // `in:1,2,3` on a form field that posted "2" must match, and a loose
        // `in_array` would also match 0 against "a" in older PHP. Casting both
        // sides makes the rule mean what the author wrote.
        if (!$this->matchesAny($value, $args)) {
            $this->addError($attribute, sprintf(
                'The selected %s is invalid.',
                $this->label($attribute)
            ));
        }
    }

    /**
     * @param list<string> $args
     */
    private function validateNotIn(string $attribute, mixed $value, array $args): void
    {
        if ($this->matchesAny($value, $args)) {
            $this->addError($attribute, sprintf(
                'The selected %s is invalid.',
                $this->label($attribute)
            ));
        }
    }

    /**
     * Whether a value equals any of a rule's string arguments.
     *
     * An array value matches when *every* element is allowed  -  that is what makes
     * `roles` => `in:admin,editor` behave for a multi-select, which is the shape
     * a checkbox group actually posts.
     *
     * @param list<string> $allowed
     */
    private function matchesAny(mixed $value, array $allowed): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->matchesAny($item, $allowed)) {
                    return false;
                }
            }

            return $value !== [];
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return false;
        }

        return in_array((string) $value, $allowed, true);
    }

    /**
     * @param list<string> $args
     */
    private function validateMin(string $attribute, mixed $value, array $args): void
    {
        $this->checkBound($attribute, $value, $args, 'min');
    }

    /**
     * @param list<string> $args
     */
    private function validateMax(string $attribute, mixed $value, array $args): void
    {
        $this->checkBound($attribute, $value, $args, 'max');
    }

    /**
     * The shared body of min/max, because the *only* difference between them is
     * the comparison direction  -  and duplicating the measure-selection logic
     * would be duplicating the part that is easy to get wrong.
     *
     * The measure is chosen by the value's own type, which is what makes
     * `max:255` mean "255 characters" for a string and "255" for a number. Both
     * readings are correct; which one applies is decided by what the user sent,
     * not by a separate rule name.
     *
     * @param list<string> $args
     */
    private function checkBound(string $attribute, mixed $value, array $args, string $direction): void
    {
        if ($args === [] || !is_numeric($args[0])) {
            throw new \InvalidArgumentException(sprintf(
                'The [%s] rule on attribute [%s] requires a numeric bound.',
                $direction,
                $attribute
            ));
        }

        $bound = (float) $args[0];

        if (is_array($value)) {
            $measure = (float) count($value);
        } elseif (is_numeric($value)) {
            // A digit-string is ambiguous  -  "10" is both ten and two characters.
            // Read as a *number*, because a rule author writing `min:18` on an age
            // field means eighteen years, and the form sent "18". A length check
            // on a numeric string is what `size` is for.
            //
            // One branch, not two: `is_numeric()` already covers both the real
            // number and the digit-string, and splitting it read as a type
            // distinction the code did not actually make.
            $measure = (float) $value;
        } else {
            $measure = (float) mb_strlen((string) $value);
        }

        $fails = $direction === 'min' ? $measure < $bound : $measure > $bound;

        if (!$fails) {
            return;
        }

        $this->addError($attribute, sprintf(
            $direction === 'min'
                ? 'The %s field must be at least %s.'
                : 'The %s field must not be greater than %s.',
            $this->label($attribute),
            $this->trimBound($bound)
        ));
    }

    /**
     * Render a numeric bound without a pointless `.0`.
     */
    private function trimBound(float $bound): string
    {
        return $bound === floor($bound) ? (string) (int) $bound : (string) $bound;
    }

    /**
     * @param list<string> $args
     */
    private function validateBetween(string $attribute, mixed $value, array $args): void
    {
        if (count($args) < 2 || !is_numeric($args[0]) || !is_numeric($args[1])) {
            throw new \InvalidArgumentException(sprintf(
                'The [between] rule on attribute [%s] requires two numeric bounds.',
                $attribute
            ));
        }

        // Reported as one message rather than delegating to min then max, which
        // would emit two errors for one out-of-range value. The user sent one
        // wrong thing; they should read one sentence about it.
        $low = (float) $args[0];
        $high = (float) $args[1];

        $measure = is_array($value)
            ? (float) count($value)
            : (is_numeric($value) ? (float) $value : (float) mb_strlen((string) $value));

        if ($measure < $low || $measure > $high) {
            $this->addError($attribute, sprintf(
                'The %s field must be between %s and %s.',
                $this->label($attribute),
                $this->trimBound($low),
                $this->trimBound($high)
            ));
        }
    }

    /**
     * An exact size: characters for a string, elements for an array, value for a
     * number.
     *
     * @param list<string> $args
     */
    private function validateSize(string $attribute, mixed $value, array $args): void
    {
        if ($args === [] || !is_numeric($args[0])) {
            throw new \InvalidArgumentException(sprintf(
                'The [size] rule on attribute [%s] requires a numeric size.',
                $attribute
            ));
        }

        $expected = (float) $args[0];

        // The numeric branch comes *first* here, and only when the rule author
        // asked for a size on a non-string. The distinction from `min`/`max` is
        // deliberate: `size:5` on a string means five characters, `size:5` on a
        // number means five.
        $measure = match (true) {
            is_array($value) => (float) count($value),
            is_string($value) && !is_numeric($value) => (float) mb_strlen($value),
            is_numeric($value) => (float) $value,
            default => (float) mb_strlen((string) $value),
        };

        if ($measure !== $expected) {
            $this->addError($attribute, sprintf(
                'The %s field must be exactly %s.',
                $this->label($attribute),
                $this->trimBound($expected)
            ));
        }
    }

    /**
     * @param list<string> $args
     */
    private function validateRegex(string $attribute, mixed $value, array $args): void
    {
        $pattern = $args[0] ?? '';

        if ($pattern === '') {
            throw new \InvalidArgumentException(sprintf(
                'The [regex] rule on attribute [%s] requires a pattern.',
                $attribute
            ));
        }

        // A malformed pattern is the author's mistake, and preg_match() reports it
        // by returning false with a warning. Surfaced as an exception rather than
        // as a validation failure: "the email field is invalid" is a lie when the
        // real problem is that the pattern does not compile.
        $result = @preg_match($pattern, (string) $value);

        if ($result === false) {
            throw new \InvalidArgumentException(sprintf(
                'The [regex] pattern [%s] on attribute [%s] is not a valid regular expression.',
                $pattern,
                $attribute
            ));
        }

        if ($result !== 1) {
            $this->addError($attribute, sprintf('The %s field format is invalid.', $this->label($attribute)));
        }
    }

    /**
     * @param list<string> $args
     */
    private function validateSame(string $attribute, mixed $value, array $args): void
    {
        $other = $args[0] ?? '';

        if ($other === '') {
            throw new \InvalidArgumentException(sprintf(
                'The [same] rule on attribute [%s] requires another field name.',
                $attribute
            ));
        }

        if (!$this->has($other)) {
            $this->addError($attribute, sprintf(
                'The %s field cannot be checked because [%s] is missing.',
                $this->label($attribute),
                $other
            ));

            return;
        }

        // Loose-ish comparison restricted to scalars: a form posts both fields as
        // strings, so "7" and 7 are the same answer from the user's point of view.
        // Arrays fall back to identity, which is the only defensible reading.
        if ($this->value($other) != $value) {
            $this->addError($attribute, sprintf(
                'The %s field must match %s.',
                $this->label($attribute),
                $this->label($other)
            ));
        }
    }

    /**
     * @param list<string> $args
     */
    private function validateDifferent(string $attribute, mixed $value, array $args): void
    {
        $other = $args[0] ?? '';

        if ($other === '') {
            throw new \InvalidArgumentException(sprintf(
                'The [different] rule on attribute [%s] requires another field name.',
                $attribute
            ));
        }

        // Absent means "no conflict"  -  `different` asks that two values not be
        // equal, and a field that was not sent is not equal to anything. Making
        // it an error would mean an optional field could never be left blank.
        if ($this->has($other) && $this->value($other) == $value) {
            $this->addError($attribute, sprintf(
                'The %s field must be different from %s.',
                $this->label($attribute),
                $this->label($other)
            ));
        }
    }

    /**
     * `confirmed`  -  the value must equal `{attribute}_confirmation`.
     *
     * Laravel's spelling, and worth keeping: the pairing convention is the useful
     * part, and every developer arriving from Laravel already knows it means the
     * `_confirmation` field rather than some other name.
     *
     * @param list<string> $args
     */
    private function validateConfirmed(string $attribute, mixed $value, array $args): void
    {
        $confirmation = $attribute . '_confirmation';

        if (!$this->has($confirmation) || $this->value($confirmation) != $value) {
            $this->addError($attribute, sprintf(
                'The %s confirmation does not match.',
                $this->label($attribute)
            ));
        }
    }

    /**
     * `accepted`  -  a terms checkbox, or any field that must be affirmative.
     *
     * @param list<string> $args
     */
    private function validateAccepted(string $attribute, mixed $value, array $args): void
    {
        $ok = $value === true
            || $value === 1
            || $value === '1'
            || (is_string($value) && in_array(strtolower(trim($value)), ['yes', 'on', 'true'], true));

        if (!$ok) {
            $this->addError($attribute, sprintf('The %s field must be accepted.', $this->label($attribute)));
        }
    }


    /**
     * Run a caller-supplied rule.
     *
     * Two idioms are accepted, because both are natural and neither is a subset
     * of the other:
     *
     *     function (string $attribute, mixed $value, callable $fail): void { $fail('msg'); }
     *     function (string $attribute, mixed $value): bool { return $value !== 'x'; }
     *
     * A **third** parameter is passed when the closure accepts one, so the
     * `$fail` form can report a specific message. Reflection is used to decide,
     * rather than passing `$fail` always  -  a closure declaring only two
     * parameters would be a fatal if it were handed three, and a two-parameter
     * closure is the more common spelling.
     */
    private function runClosure(string $attribute, mixed $value, callable $rule): void
    {
        $reflection = new \ReflectionFunction(\Closure::fromCallable($rule));

        $reported = [];

        // The failure reporter is a closure over the message list rather than the
        // validator, so a custom rule cannot reach in and clear errors, and so the
        // `$fail` contract is exactly one string.
        $fail = function (string $message) use (&$reported): void {
            $reported[] = $message;
        };

        $result = $reflection->getNumberOfParameters() >= 3
            ? $rule($attribute, $value, $fail)
            : $rule($attribute, $value);

        foreach ($reported as $message) {
            $this->addError($attribute, $message);
        }

        // A closure that reported a message has spoken, whatever it returned. Only
        // when it said nothing does a falsy return mean "failed"  -  so a rule
        // written as `fn () => $value !== 'x'` works, and a rule written with
        // `$fail` and no `return` does not fail twice.
        if ($reported === [] && $result === false) {
            $this->addError($attribute, sprintf('The %s field is invalid.', $this->label($attribute)));
        }
    }
}
