<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;

/**
 * Base for middleware that rewrites the request's input before the stack sees it.
 *
 * {@see TrimStrings}, {@see ConvertEmptyStringsToNull} and {@see SanitizeInput}
 * differ only in the function they apply to each leaf; walking the nested array,
 * preserving its shape, and handing the result on are identical. That is what
 * this class holds — the part where the three would otherwise be three copies of
 * the same recursion, each with its own chance to get the array handling wrong.
 *
 * ## The recursion, and why keys are left alone
 *
 * Input arrives nested: a form can post `lead[email]`, a JSON body can carry
 * arbitrary depth. `apply()` therefore returns a *new* array with the same keys
 * and the same shape, transforming only the leaves — the values, never the keys.
 * A key is structural: it is what every downstream `input('lead.email')` and
 * every validation rule is matched against, and a transform that renamed one
 * would make a field unreachable rather than clean.
 *
 * Non-string leaves — ints, floats, bools, null — are passed through untouched.
 * A scalar from JSON is already the type it claims to be, and coercing it to a
 * string to "clean" it would change `42` into `'42'`, which is a bug in every
 * consumer that then compares it. Only strings are the business of these three
 * middleware; a value that is not a string has nothing to trim or sanitize.
 *
 * ## The transformed request is handed to `$next`, not written back
 *
 * {@see Request} is immutable, so {@see Request::withInput()} returns a copy. The
 * *copy* is what `$next` receives, and the original is untouched for whoever
 * holds it — the adapter's binding, a log, an outer layer that wants the raw
 * request on the way back out. Nothing downstream can reach the untransformed
 * values through the request it was given.
 *
 * ## Order matters, and the route says it
 *
 * `SanitizeInput` strips tags and collapses whitespace, so running it **before**
 * `TrimStrings` means the trim still has something to do afterwards; running it
 * after means the trim is largely redundant. Neither order is wrong, and a
 * middleware that silently reordered the two would be lying about what the
 * routes file says. The pipeline runs layers in the order declared, and these
 * three are ordinary layers — the order in `routes/web.php` is the order that
 * happens.
 */
abstract class TransformsInput implements Middleware
{
    public function handle(Request $request, Closure $next): ResponseInterface
    {
        return $next($this->transform($request));
    }

    /**
     * The request with this middleware's transform applied to its input.
     */
    protected function transform(Request $request): Request
    {
        return $request->withInput(fn (array $input): array => $this->apply($input));
    }

    /**
     * Apply the transform to every string leaf, preserving the array's shape.
     *
     * @param array<int|string, mixed> $input
     * @return array<int|string, mixed>
     */
    protected function apply(array $input): array
    {
        $result = [];

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->apply($value);

                continue;
            }

            $result[$key] = is_string($value) ? $this->applyToString($value) : $value;
        }

        return $result;
    }

    /**
     * The transform for one string value.
     *
     * Returns `mixed` rather than `string` because the transform is allowed to
     * change the *type*: {@see ConvertEmptyStringsToNull} turns `''` into null,
     * and a contract of `: string` would force it to lie or to invent a
     * sentinel. A subclass that returns a string is the ordinary case.
     *
     * @return mixed
     */
    abstract protected function applyToString(string $value): mixed;
}
