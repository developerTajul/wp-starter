# Routing System Audit Report
**Date:** 2026-11-09
**Scope:** `routes/web.php`, `routes/api.php`, route registrars/facades, controllers, parameter binding, middleware (capabilities/nonces/validation), URL generation (`route()` helper)

## Executive Summary

The routing system is architecturally mature, well-tested, and largely secure. The three-layer design (facade `Route` → `RouteRegistrar` → `AbstractRouterAdapter`) is clean, both web and REST surfaces are properly guarded, and parameter binding follows secure precedence rules.

However, **two critical issues require immediate action**, and several medium/low improvements are recommended.

**Overall health: GOOD, but requires immediate attention.**

---

## CRITICAL / HIGH SEVERITY — Immediate Action Required

### 1. 🔴 CRITICAL — Open Redirect vulnerability (`FormRequest::redirectTarget()`)

**File:** `framework\Http\Requests\FormRequest.php`, `redirectTarget()` (~lines 516–536)

After validating that a `redirect_to` field or referer points to *this site*, the code returns the URL **without validating its scheme, port, or path**. `isLocalUrl()` compares hosts **case-insensitively only**, and `wp_safe_redirect()` semantics are not used.

- An attacker (anyone who can submit the add-company form, or any future form) can craft `redirect_to=http://evil.com/steal?token={value}` and force the browser to a phishing page.
- Missing protections vs. WordPress's `wp_safe_redirect()`: protocol-relative URLs (`//`), scheme attacks (`javascript:`, `data:`), port mismatch, `wp_parse_url()`-style normalization.

**Fix:** See Fix 1 in Recommendations — replace `redirectTarget()` / `isLocalUrl()` with `wp_validate_redirect()` plus scheme/protocol-relative guards.

### 2. 🔴 CRITICAL — `StoreCompanyRequest::rules()` is a PHP parse error

**File:** `app\Http\Requests\Admin\StoreCompanyRequest.php`, ~lines 43–52

The `rules()` method returns the array **prematurely** (`return [ ... ];` ends the method), leaving `'industry'`, `'phone'`, `'address'` as **orphaned, unparseable code** after the closing `];`. As shipped, this file does not parse and the plugin produces a "Parse error" on any request.

**Fix:** Remove the intermediate `return` and trailing `];`, letting the final `];` close the array.

### 3. 🟠 HIGH — Unbounded `int` coercion enables IDOR / out-of-range IDs

**File:** `framework\Routing\ParameterBinder.php`, `accepts()` (`'int'` arm, ~line 500)

```php
'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
```

Values like `"999999999999999999999999"` pass and become `(int)` — overflowing to a float or negative garbage. This lets an attacker address out-of-range IDs.

**Fix:** Clamp to `PHP_INT_MIN`/`PHP_INT_MAX` before accepting.

---

## MEDIUM SEVERITY — Recommended

### 4. 🟡 `SanitizeInput::applyToString()` destroys multi-line content

**File:** `framework\Http\Middleware\SanitizeInput.php`

`sanitize_text_field()` strips newlines. The framework's own docblock warns: "A route with a genuine multi-line field should not run this middleware." All current company form fields (`name`, `website`, `industry`, `phone`, `address` — all `max:255`/`max:20`) are single-line safe, but any future textarea added to a route running this middleware silently loses line breaks.

**Action:** Audit forms for multi-line fields; opt routes out of `SanitizeInput` (use `wp_kses_post()` instead) for textareas, per the documented pattern.

### 5. 🟡 Silent dropping of params on admin-post URL generation

**File:** `framework\Routing\UrlGenerator.php`, `compileAdminAction()` (~line 226)

`route('company.store', ['id' => 5])` silently drops `id` (admin-post URLs cannot carry placeholders). It would be friendlier to throw a `RuntimeException` naming the dropped params rather than generating a misleading URL. Not a security issue.

### 6. 🟡 Silent privilege-misconfiguration via wrong `RequireCapability` binding

**File:** `framework\Routing\Middleware\RequireCapability.php` + `app/Http/Middleware/RequireLeadRead.php`

Binding on the base class (`RequireCapability`) rather than the concrete subclass (`RequireLeadRead`) is a **no-op that errors on nothing** — a misconfigured capability silently stays at the default. Documented in the docblock, but this is the exact class of silence security reviews flag.

**Action:** Keep the required subclassing pattern (already the documented, correct approach). Add a container-level check that throws if `RequireCapability` is bound to anything other than a `RequireCapability` subclass — optional improvement.

### 7. 🟡 Public `ping` endpoint unguarded (acceptable but undocumented)

**File:** `routes\api.php`, line 16

The health check is deliberately public for load-balancer probes — no `permission_callback`, no middleware. This is standard practice, but it must be documented so future reviewers don't assume it's an oversight.

### 8. 🟡 CSRF gap for future AJAX / front-end form submissions

The framework guards `admin_post` handlers via the nonce middleware. Front-end `page()` surface routes and any future AJAX handlers have **no demonstrated nonce pattern**, so adding them without minting/verifying a nonce would be open to CSRF. Out of current scope (no such handlers exist), but record as an architectural gap and document in the style guide.

---

## LOW SEVERITY — Notes / Optional

- **#9** `accepts()` accepting `''` for bool, normalized to `false` — deliberate Laravel-compatible behavior. No change.
- **#10** `VerifyAdminNonce` gives a generic "security check is missing" message; doesn't distinguish stale vs. missing nonce. Minor UX, both are 403. No change.
- **#11** `manage_options` hard constant on `CompanyController::CAPABILITY` — deliberate (see docblock); capability checked twice (menu gate + middleware), consistently. No change.
- **#12** `DashboardController` hardcodes `'wp wp_mini route:list'` — display-only, safe. No change.

---

## VERIFIED SECURE (no action needed)

| Item | Evidence |
|---|---|
| Parameter precedence: placeholder > body value of same name | `ParameterBinder::bindParameters()` reads route params first; REST adapter repins via `$request->set_param()` |
| REST uses `permission_callback` (not web middleware strings) | `routes/api.php` + `RestApiRouterAdapter::buildPermissionCallback()` (`false`/`null`/`WP_Error` denial handled) |
| Web middleware order: auth → capability → nonce → trim → null → sanitize | `routes/web.php` lines 35–72 |
| Duplicate `admin_post_{action}` names rejected at flush | `WordPressRouterAdapter::resolveAdminActions()` → `RouteRegistrationException::duplicateAction()` |
| Handler-less routes rejected before WordPress registration | Both adapters loop-validate all routes in `publish()` |
| Two middleware contracts, not merged; wrong-contract middleware rejected on web routes | `WordPressRouterAdapter::assertHttpMiddleware()` |
| FormRequest validation short-circuits the action (never runs on invalid input) | `FormRequestBindingTest::test_an_invalid_form_request_never_reaches_the_action` (asserts `$calls === 0`) |
| Bool coercion: `"false"`/`"0"` → `false`, `"true"`/`"1"` → `true` | `ParameterBinder::normalize()` |
| Ambiguous route names across domains detected | `NamedRoutes::ambiguity()` |
| Nonce verified against the exact minted action | `VerifyAdminNonce` + per-route `'nonce:'` config |
| Controllers `final`, all files `declare(strict_types=1)` | CompanyController, LeadController, DashboardController |
| Only whitelisted request keys reach the controller | `StoreCompanyRequest::validated()` |
| Facade target swapped per routes file, restored in `finally` | `Route::withRegistrar()` |
| Route name uniqueness / ambiguity reporting | `NamedRoutes` |

---

## RECOMMENDATIONS & CODE FIXES

### Fix 1 — Replace the open-redirect-prone redirect logic
**File:** `framework\Http\Requests\FormRequest.php`

Replace `redirectTarget()` and `isLocalUrl()` with WordPress-native validation:

```php
private function redirectTarget(): string
{
    $explicit = $this->redirectTo();
    if (is_string($explicit) && $explicit !== '') {
        return $this->validateLocalUrl($explicit);
    }

    $field = $this->input('redirect_to');
    if (is_string($field) && $field !== '') {
        return $this->validateLocalUrl($field);
    }

    $referer = $this->request?->header('referer');
    if (is_string($referer) && $referer !== '') {
        return $this->validateLocalUrl($referer);
    }

    return $this->siteHome();
}

private function validateLocalUrl(string $url): string
{
    // WordPress's own check: rejects protocol-relative URLs,
    // scheme/port mismatches, and javascript:/data: attacks.
    if (function_exists('wp_validate_redirect')) {
        return wp_validate_redirect($url, $this->siteHome());
    }

    // Fallback for test/CLI contexts without WordPress.
    if (!function_exists('home_url')) {
        return $this->siteHome();
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return $this->siteHome();
    }

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme !== null && $scheme !== ''
        && !in_array(strtolower($scheme), ['http', 'https'], true)
    ) {
        return $this->siteHome();
    }

    if (str_starts_with($url, '//')) {
        return $this->siteHome();
    }

    $siteHost = parse_url(home_url(), PHP_URL_HOST);
    if (!is_string($siteHost) || $siteHost === '') {
        return $this->siteHome();
    }

    return strcasecmp($host, $siteHost) === 0 ? $url : $this->siteHome();
}
```

### Fix 2 — Repair `StoreCompanyRequest::rules()`
**File:** `app\Http\Requests\Admin\StoreCompanyRequest.php`

```php
    protected function rules(): array
    {
        return [
            'name'    => 'required|string|max:255',
            'website' => 'nullable|url',
            'industry' => 'nullable|string|max:100',
            'phone'    => 'nullable|string|max:20',
            'address'  => 'nullable|string',
        ];
    }
```

### Fix 3 — Clamp `int` coercion to integer range
**File:** `framework\Routing\ParameterBinder.php`, `accepts()`

```php
'int' => is_int($value)
    || (
        is_string($value)
        && preg_match('/^-?\d+$/', $value) === 1
        && $value >= PHP_INT_MIN
        && $value <= PHP_INT_MAX
    ),
```

### Fix 4 — Document the public health check
**File:** `routes\api.php`

```php
// Health check — deliberately public so load balancers can probe without auth.
Route::get('ping', static fn (): array => ['pong' => true])->name('ping');
```

### Fix 5 (optional) — Loudly reject dropped params for admin-post URLs
**File:** `framework\Routing\UrlGenerator.php`, `compileAdminAction()`

```php
private function compileAdminAction(Route $route): string
{
    if ($parameters !== []) {
        throw new RuntimeException(sprintf(
            'Route [%s] is a form handler (admin-post) and cannot carry URL parameters; '
            . 'pass them as form fields, not as route() parameters.',
            $route->getName() ?? $route->getPath()
        ));
    }

    $action = $this->actionName($route);

    return $this->url('admin_url', 'admin-post.php?action=' . rawurlencode($action));
}
```

### Fix 6 (optional) — Refuse base-class bindings on `RequireCapability`
Add in the provider or a static guard in `RequireCapability::handle()`:
- throw if `$this->capability` is set to the base class's default `manage_options` via a base-class binding rather than the subclass constructor. (Documented pattern already enforces subclassing; this makes mis-binding loud.)

### Fix 7 (optional) — Style-guide entry: AJAX / front-end CSRF
Document that any AJAX handler or front-end form on a `page()`-surface route must mint (`wp_create_nonce()`) and verify a nonce; the framework's nonce middleware only guards `admin_post` handlers.

---

## Files Reviewed (evidence base)

- `routes/web.php` — web surfaces (admin screens, admin-post form handlers)
- `routes/api.php` — REST endpoints
- `app/Http/Controllers/Admin/CompanyController.php`
- `app/Http/Controllers/Admin/DashboardController.php`
- `app/Http/Controllers/Api/LeadController.php`
- `app/Http/Middleware/RequireLeadRead.php`
- `app/Http/Requests/Admin/StoreCompanyRequest.php`
- `framework/Routing/RouteRegistrar.php`, `WebRouteRegistrar.php`
- `framework/Routing/Route.php`, `AbstractRouterAdapter.php`, `WordPressRouterAdapter.php`, `RestApiRouterAdapter.php`
- `framework/Routing/ParameterBinder.php`, `RouteResolver.php`, `UrlGenerator.php`, `NamedRoutes.php`
- `framework/Routing/Attributes/Route.php`, `RouteDomain.php`, `AdminPage.php`
- `framework/Routing/Adapters/AbstractRouterAdapter.php`
- `framework/Routing/Facades/Route.php`
- `framework/Http/Middleware/VerifyAdminNonce.php`, `CheckCapability.php`, `SanitizeInput.php`, `TrimStrings.php`, `ConvertEmptyStringsToNull.php`, `Pipeline.php`, `RequireCapability.php`
- `framework/Http/Requests/FormRequest.php`
- `framework/Routing/ServiceProvider.php`, `app/Providers/RouteServiceProvider.php`, `RepositoryServiceProvider.php`
- `tests/Unit/Routing/RouteRegistrarTest.php`, `ParameterBinderTest.php`, `FormRequestBindingTest.php`

---

## Audit Checklist (self-check)

- [x] Web routes use explicit surfaces: `get()`/`post()` verb→surface mapping, `withOptions()` for page_title/menu_title/capability
- [x] API routes in `routes/api.php` with `permission_callback` via middleware translation; REST responses via `JsonResponse`
- [x] Controllers are `final`; parameter binding via `ParameterBinder` (REST `WP_REST_Request`, web `Framework\Http\Request`)
- [x] Form submissions validated via `FormRequest` (`StoreCompanyRequest`); `validated()` returns only whitelisted keys
- [x] Web: nonce (`VerifyAdminNonce`) + capability (`CheckCapability`) middleware on POST handlers
- [x] REST: `RequireLeadRead` → `permission_callback` returning `WP_Error` or `bool`
- [x] Input trimming (`TrimStrings`, `ConvertEmptyStringsToNull`) and sanitizing (`SanitizeInput`) on web routes
- [x] `route()` helper resolves admin screens, admin-post actions, and REST endpoints via `UrlGenerator`
- [ ] **FAIL:** `redirectTarget()` uses host-only comparison — OPEN REDIRECT (Fix 1)
- [ ] **FAIL:** `StoreCompanyRequest::rules()` has dead/invalid PHP — PARSE ERROR (Fix 2)
- [ ] **FAIL:** `int` coercion unbounded — IDOR risk (Fix 3)
- [x] All other security controls verified against actual code
