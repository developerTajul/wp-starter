<?php
declare(strict_types=1);

namespace Framework\Http\Requests;

use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\RedirectResponse;
use Framework\Validation\Validator;

/**
 * A request that validates itself and answers its own failure.
 *
 * Type-hint a subclass on a controller action:
 *
 *     final class StoreLeadRequest extends FormRequest
 *     {
 *         protected function rules(): array
 *         {
 *             return [
 *                 'email' => 'required|email|max:255',
 *                 'name'  => 'required|string|max:100',
 *             ];
 *         }
 *     }
 *
 *     public function store(StoreLeadRequest $request): ResponseInterface
 *     {
 *         $data = $request->validated();   // only the keys the rules named
 *     }
 *
 * The parameter binder recognises the type, builds the object around the current
 * request, and validates it. The action is never entered when validation fails:
 * the binder returns this object's failure response instead. A controller cannot
 * forget to validate, which is the point, since validation driven by the author's
 * memory is validation eventually missing from one endpoint.
 *
 * This wraps a Request rather than extending it. Laravel's `FormRequest` extends
 * its `Request`, and copying that here is the wrong trade:
 * `Framework\Http\Request` promotes its parts to `readonly` so a request cannot
 * be mutated after capture, which is what makes it safe to bind into the container
 * for the life of a dispatch. A subclass that had to become the current request
 * would have to re-seat those properties after construction, and PHP refuses: a
 * `readonly` property initialised once cannot be reassigned, and re-invoking the
 * parent constructor on a built object is a fatal.
 *
 * Composition sidesteps that. The container builds this class with no arguments
 * and the binder hands it the request, so nothing `readonly` is mutated and
 * `Request` keeps its invariant. The cost is that `$request->input()` is not
 * available directly; the accessors below forward it, and {@see request()} gives
 * the underlying object.
 *
 * The failure mode is decided by the client, not the route:
 *
 *  - Ajax / headless (`expectsJson()`) gets a `422` with the errors as JSON. A
 *    redirect to an HTML page would be useless to a client that cannot render
 *    one, which needs the messages programmatically to mark up fields.
 *  - A browser form post gets the errors and old input flashed into a transient
 *    and a redirect back to the form. A 422 page in a browser is a dead end: the
 *    user's typing is gone and there is no field to attach the error to.
 *
 * This is the only class that has to know both exist. {@see Validator} reports
 * errors and never redirects; {@see RedirectResponse} moves a browser and never
 * validates. Putting the branch in either would bind a general tool to one
 * caller.
 *
 * `expectsJson()` rather than the route's domain, because a route in
 * `routes/api.php` is not the test: an admin screen whose form is submitted by
 * `fetch()` is an HTML route that wants a JSON error, and a REST route hit from a
 * browser's address bar wants a page. The request carries the answer in `Accept`
 * (see {@see Request::expectsJson()}), so one rule covers both files.
 */
abstract class FormRequest
{
    /**
     * The request being validated.
     *
     * Null until {@see fromRequest()} or {@see setRequest()} has run, which is
     * what makes a container-built instance detectably incomplete rather than
     * silently validating an empty payload.
     */
    private ?Request $request = null;

    /**
     * The validator that ran, once `validate()` has been called. Non-null is the
     * "already validated" flag.
     */
    private ?Validator $validator = null;

    /**
     * The errors from the last run, keyed by field.
     *
     * @var array<string, list<string>>
     */
    private array $errors = [];


    /**
     * The validation rules, as {@see Validator} understands them.
     *
     * @return array<string, string|array<mixed>|callable>
     */
    abstract protected function rules(): array;

    /**
     * An author's own check, run after the rules pass.
     *
     * Running second is what lets it rely on the data being structurally sound: a
     * hook that reads `$this->input('id')` should not also have to defend against
     * the id being an array. Returning a message string rather than `false` makes
     * the failure explain itself; the message is attached to the field named by
     * {@see failedField()}.
     *
     * @return bool|string True to continue, false or a message to fail.
     */
    protected function authorize(): bool|string
    {
        return true;
    }

    /**
     * Which field an `authorize()` message is attached to.
     *
     * `_form` by convention  -  the bucket a view reads for errors belonging to the
     * form as a whole rather than to one input.
     */
    protected function failedField(): string
    {
        return '_form';
    }

    /**
     * Replace the message a rule produces.
     *
     * Keyed `attribute.rule`  -  `'email.required' => 'We need your email.'`  -  or
     * by bare attribute to replace every message on that field. Applied *after*
     * validation, so an override is purely presentational and can never change
     * whether something passed.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [];
    }

    /**
     * Human-readable names for the fields, used in the default messages.
     *
     * @return array<string, string>
     */
    protected function attributes(): array
    {
        return [];
    }

    /**
     * Where to send a browser after a failed validation.
     *
     * Null means "work it out"  -  see {@see redirectTarget()}.
     */
    protected function redirectTo(): ?string
    {
        return null;
    }


    /**
     * Wrap a request in a new instance of this form request.
     *
     * The binder's entry point. A static factory rather than a constructor
     * argument because the container resolves a class-typed parameter by
     * constructing it with no arguments  -  it has no way to hand this class the
     * request being dispatched. A constructor that required one would make every
     * `FormRequest` parameter unresolvable.
     */
    public static function fromRequest(Request $request): static
    {
        $form = new static();
        $form->request = $request;

        return $form;
    }

    /**
     * Attach the request, for a caller that constructed the object directly.
     *
     * Present for tests and for hand-wired code. The binder uses
     * {@see fromRequest()}.
     */
    public function setRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Run the rules and record any failures.
     *
     * Idempotent: the second call is a no-op. The binder may legitimately reach
     * the same instance twice  -  a middleware that resolves the form request too,
     * a dispatch retried  -  and re-running the rules would append a second copy of
     * every message.
     */
    public function validate(): static
    {
        if ($this->validator !== null) {
            return $this;
        }

        // A missing request is a wiring mistake, and the failure mode if it were
        // tolerated is the worst kind: validating `[]` reports every required
        // field as missing, so the symptom would be "the form rejects everything"
        // rather than "the binder did not run".
        if ($this->request === null) {
            throw new \LogicException(sprintf(
                '%s was validated without a request. Build it with %s::fromRequest($request) '
                . ' -  the parameter binder does this automatically for a type-hinted action parameter.',
                static::class,
                static::class
            ));
        }

        $validator = new Validator($this->request->all(), $this->attributes());
        $this->errors = $validator->validate($this->rules());
        $this->validator = $validator;

        $this->applyMessageOverrides();

        // Only when the data is structurally sound: a hook inspecting a field
        // should not have to defend against the field being the wrong type, and
        // reporting "name must be a string" *and* "you may not do this" for one
        // broken payload is noise.
        if ($this->errors === [] && ($result = $this->authorize()) !== true) {
            $this->errors[$this->failedField()][] = is_string($result) && $result !== ''
                ? $result
                : 'This action is unauthorized.';
        }

        return $this;
    }

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
     * The first message recorded for a field, or null.
     */
    public function firstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /**
     * The values that passed validation  -  and *only* those.
     *
     * This is the security-relevant method, and the reason merits stating
     * plainly: `$request->input('is_admin')` returns whatever the client sent,
     * whether or not a rule mentioned it, so a controller building a record from
     * `all()` accepts every field the client chose to include. `validated()`
     * returns just the keys the rules named, so a field the author did not think
     * about cannot arrive through it.
     *
     * **A field with no rule is not returned.** That is deliberate, and it is the
     * usual surprise: if a key seems to be missing, it has no rule.
     *
     * Values are the *raw* input, not a transformed one  -  `"30"` stays the string
     * `"30"` under an `integer` rule, because a rule checks a value rather than
     * casting it. A `validated()` that silently retyped its output would make the
     * controller's own casts redundant and its types unpredictable. Cast where it
     * matters.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $all = $this->request?->all() ?? [];
        $validated = [];

        foreach (array_keys($this->rules()) as $attribute) {
            $this->collect($validated, (string) $attribute, $all);
        }

        return $validated;
    }

    /**
     * One validated value, or a default.
     *
     * @param mixed $default
     * @return mixed
     */
    public function safe(string $attribute, mixed $default = null): mixed
    {
        return $this->validated()[$attribute] ?? $default;
    }

    /**
     * The response a failed validation produces, shaped for the client.
     *
     * Called by the parameter binder, which returns it *instead of* invoking the
     * action. Calling it after a validation that passed is refused rather than
     * producing an empty error payload some caller might send.
     *
     * @return ResponseInterface|\WP_Error
     */
    public function failureResponse(): ResponseInterface|\WP_Error
    {
        if ($this->validator === null) {
            throw new \LogicException(sprintf(
                '%s::failureResponse() was called before validate(). The binder always validates '
                . 'first; a direct caller must not skip it.',
                static::class
            ));
        }

        // A REST route gets a WP_Error, because that is the only thing WordPress's
        // REST server renders  -  a JsonResponse returned from a REST callback is
        // serialized as an opaque object and the 422 never happens. The two
        // domains are distinguished by the *marker the adapter primes*, not by
        // sniffing the request, because a REST dispatch and a web dispatch can
        // carry byte-identical requests.
        if ($this->isRestDispatch()) {
            return $this->restFailureResponse();
        }

        return $this->request->expectsJson()
            ? $this->jsonFailureResponse()
            : $this->redirectFailureResponse();
    }


    /**
     * The request this form request wraps.
     */
    public function request(): Request
    {
        if ($this->request === null) {
            throw new \LogicException(sprintf('%s has no request attached.', static::class));
        }

        return $this->request;
    }

    /**
     * One parameter, exactly as {@see Request::input()} reads it.
     *
     * @param mixed $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request?->input($key, $default) ?? $default;
    }

    /**
     * Every parameter, merged.
     *
     * @return array<int|string, mixed>
     */
    public function all(): array
    {
        return $this->request?->all() ?? [];
    }

    /**
     * Whether the client wants a JSON answer.
     */
    public function expectsJson(): bool
    {
        return $this->request?->expectsJson() ?? false;
    }


    /**
     * Whether the current dispatch is a REST route.
     *
     * A marker rather than a request inspection, because a REST request and a
     * web request carrying the same body, method and headers are byte-identical
     * by the time a form request sees them: the REST adapter builds a framework
     * `Request` from the `WP_REST_Request` precisely so the two domains agree
     * everywhere else. Nothing observable in the request distinguishes them, so
     * the code that knows states it:
     * {@see \Framework\Routing\Adapters\RestApiRouterAdapter} sets this flag
     * around the dispatch and clears it afterwards.
     *
     * Static because {@see fromRequest()} builds the form request inside the
     * binder, several frames below the adapter, with no seam to thread an
     * instance value through. The adapter clears it in a `finally`, so it cannot
     * outlive the dispatch that set it.
     */
    public static bool $restDispatch = false;

    /**
     * Whether the dispatch currently in progress is a REST route.
     *
     * A method rather than reading the static directly, so a subclass  -  or a
     * consuming project with a different idea of what "REST" means, such as an
     * admin-ajax handler that also wants `WP_Error` failures  -  has one place to
     * override.
     */
    protected function isRestDispatch(): bool
    {
        return static::$restDispatch;
    }

    /**
     * Answer a REST validation failure the way WordPress can render it.
     *
     * A `WP_Error` with a `status` is what the REST server turns into an error
     * response, and the `errors` key rides along in the error data so a client
     * gets the per-field map exactly as the JSON path provides it. Returning a
     * `JsonResponse` here would not work: the REST server does not send framework
     * responses, it serializes the returned value, and a response object
     * serializes to something a client cannot read.
     */
    private function restFailureResponse(): object
    {
        // Outside WordPress there is no WP_Error to build, and a REST dispatch
        // cannot happen anyway  -  the adapter's own entry points require WordPress.
        // Falling back to the JSON response keeps this class loadable and testable
        // without the CMS, which is the same guard the rest of the framework uses.
        if (!class_exists(\WP_Error::class)) {
            return $this->jsonFailureResponse();
        }

        return new \WP_Error(
            'validation_failed',
            'The given data was invalid.',
            [
                'status' => 422,
                'errors' => $this->errors,
            ]
        );
    }

    /**
     * A 422 carrying the errors, for a client that asked for JSON.
     *
     * The shape is deliberately close to Laravel's  -  a `message` plus an `errors`
     * map keyed by field  -  because a front end already written against that
     * convention reads this one unchanged, and inventing a second shape for the
     * same idea is how a project ends up with two error handlers.
     */
    private function jsonFailureResponse(): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'errors' => $this->errors,
            ],
            [
                'status_code' => 422,
                // Unescaped slashes and unicode, so an email address or URL in a
                // message reads as itself in a client's network tab; a `\/` in an
                // error string looks like a bug in the message.
                'flags' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ]
        );
    }

    /**
     * A redirect back to the form, with the errors and the old input flashed.
     *
     * The input is carried as well as the errors because a redirect discards the
     * POST body: without it, every field the user filled in correctly comes back
     * empty, the most infuriating behaviour a form can have.
     *
     * The third argument is the request because {@see RedirectResponse} uses it
     * to decide the flash cookie's `Secure` flag, which is only correct behind a
     * TLS-terminating proxy when it can consult `X-Forwarded-Proto`, and only the
     * Request sees that.
     */
    private function redirectFailureResponse(): RedirectResponse
    {
        return (new RedirectResponse($this->redirectTarget(), 302, $this->request))
            ->with('errors', $this->errors)
            ->with('old', $this->oldInputFor())
            // One summary line for a notice above the form, so a view does not
            // have to know how to flatten the error map to say anything at all.
            ->with('error', $this->firstError('_form') ?? 'Please correct the errors below.');
    }

    /**
     * Where the browser should land.
     *
     * The author's `redirectTo()` wins. Otherwise a `redirect_to` field, which is
     * how a form states its own return address: the mechanism an `admin_post`
     * handler needs, because the form lives on an admin screen whose URL is not
     * the form's `action`. Then the referer, but only when it is on this site.
     *
     * "Back" is not simply the referer. WordPress has no `url()->previous()`, and
     * `HTTP_REFERER` is client-supplied and routinely absent (a page with a strict
     * referrer policy sends none at all), so it is a candidate rather than the
     * answer, and it is checked against the site host before use: redirecting to
     * an unvalidated client-supplied URL is an open redirect.
     */
    private function redirectTarget(): string
    {
        $explicit = $this->redirectTo();

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $field = $this->input('redirect_to');

        if (is_string($field) && $field !== '') {
            return $field;
        }

        $referer = $this->request?->header('referer');

        if (is_string($referer) && $referer !== '' && $this->isLocalUrl($referer)) {
            return $referer;
        }

        return $this->siteHome();
    }

    /**
     * Whether a URL points at this site, compared by host.
     *
     * Anything that fails to parse, or whose host differs, is not ours. The
     * scheme is not compared: an HTTPS page referring to the site's HTTP home
     * URL is the same site. This is a "did the user come from here" check, not
     * the security boundary  -  {@see RedirectResponse} validates the destination
     * again through `wp_safe_redirect()`.
     */
    private function isLocalUrl(string $url): bool
    {
        if (!function_exists('home_url')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        $siteHost = parse_url(home_url(), PHP_URL_HOST);

        return is_string($siteHost) && strcasecmp($host, $siteHost) === 0;
    }

    /**
     * A sensible page to fall back to when no better target exists.
     *
     * In wp-admin the site front end is usually not where the form was, so the
     * admin root is a closer guess than the home page.
     */
    private function siteHome(): string
    {
        if (function_exists('is_admin') && function_exists('admin_url') && is_admin()) {
            return admin_url();
        }

        return function_exists('home_url') ? home_url() : '/';
    }

    /**
     * The input worth re-rendering, with secrets and non-values removed.
     *
     * @return array<string, mixed>
     */
    private function oldInputFor(): array
    {
        $old = [];

        foreach ($this->all() as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            // Matched case-insensitively and by fragment, because `Password`,
            // `new_password` and `user_password` arrive as often as the exact
            // spelling  -  a rule that caught only one of them would look like it
            // worked and would not.
            $name = strtolower((string) $key);

            if (
                str_contains($name, 'password')
                || str_contains($name, 'secret')
                || str_contains($name, 'token')
                || in_array($name, ['_wpnonce', '_wp_http_referer', '_method'], true)
            ) {
                continue;
            }

            // A file cannot round-trip through a form field, and an array would
            // be re-rendered as the literal text "Array". Both are dropped rather
            // than mangled: a file input has to be re-chosen, which is a browser
            // constraint this layer cannot fix.
            //
            // Arrays are dropped only for re-rendering, not from `validated()`  - 
            // a multi-select still validates and still reaches the controller.
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $old[$key] = $value;
        }

        return $old;
    }


    /**
     * Apply the author's `messages()` overrides onto the produced errors.
     *
     * Rewording happens afterwards, on the messages, rather than being threaded
     * into the validator as a lookup table. The validator is the pure rule
     * engine; teaching it a key-to-message map for one caller's benefit would
     * couple it to a presentation concern it has no other use for.
     */
    private function applyMessageOverrides(): void
    {
        $overrides = $this->messages();

        if ($overrides === [] || $this->errors === []) {
            return;
        }

        foreach ($this->errors as $attribute => $messages) {
            $rules = $this->ruleNamesFor($attribute);

            foreach ($messages as $index => $message) {
                // Preferred: match by the rule that produced the message. A
                // positional guess would be wrong as soon as two rules run on one
                // field, and a wrongly-applied message is worse than a missed
                // override  -  the missed one is visible, the wrong one is not.
                foreach ($rules as $rule) {
                    if (isset($overrides[$attribute . '.' . $rule])) {
                        $this->errors[$attribute][$index] = $overrides[$attribute . '.' . $rule];

                        continue 2;
                    }
                }

                // Fallback: an override for the whole attribute.
                if (isset($overrides[$attribute])) {
                    $this->errors[$attribute][$index] = $overrides[$attribute];
                }
            }
        }
    }

    /**
     * The rule names declared for one attribute, wildcards resolved.
     *
     * `tags.0`  -  an expanded wildcard  -  is matched back to the `tags.*` rule.
     *
     * @return list<string>
     */
    private function ruleNamesFor(string $attribute): array
    {
        $rules = $this->rules();

        if (isset($rules[$attribute])) {
            return $this->namesOf($rules[$attribute]);
        }

        if (preg_match('/^(.+)\.\d+$/', $attribute, $m) === 1 && isset($rules[$m[1] . '.*'])) {
            return $this->namesOf($rules[$m[1] . '.*']);
        }

        return [];
    }

    /**
     * @param string|array<mixed>|callable $rules
     * @return list<string>
     */
    private function namesOf(string|array|callable $rules): array
    {
        if (!is_string($rules) && !is_array($rules)) {
            return [];
        }

        $names = [];

        foreach (is_array($rules) ? $rules : explode('|', $rules) as $rule) {
            if (!is_string($rule) || trim($rule) === '') {
                continue;
            }

            $names[] = strtolower(trim(explode(':', $rule, 2)[0]));
        }

        return array_values(array_filter($names));
    }

    /**
     * Copy one validated path out of the merged input.
     *
     * Dotted paths are rebuilt as nested arrays, so a rule on `user.name` yields
     * `['user' => ['name' => ...]]` rather than a literal `'user.name'` key  - 
     * which is the shape a controller building a record actually wants.
     *
     * @param array<string, mixed> $into
     * @param array<int|string, mixed> $from
     */
    private function collect(array &$into, string $attribute, array $from): void
    {
        // A wildcard names a *set* of paths, not a value; the parent key carries
        // the whole array, and it is the parent that must have a rule for it to
        // appear. Expanding here would try to key the result by `*`.
        if (str_contains($attribute, '*')) {
            return;
        }

        $segments = explode('.', $attribute);
        $cursor = $from;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                // Absent. A rule that did not require it may still legitimately
                // have nothing here, so this is a skip and not an error.
                return;
            }

            $cursor = $cursor[$segment];
        }

        $target = &$into;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $target[$segment] = $cursor;

                break;
            }

            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }
    }
}
