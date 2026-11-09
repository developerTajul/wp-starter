<?php
declare(strict_types=1);

/**
 * Probe: does `CompanyController::store()` validate before it runs?
 *
 * The claim being tested is not "the rules are spelled correctly" — it is the
 * stronger one a `FormRequest` type-hint is supposed to make: **a submission that
 * fails its rules never enters the method**. That is what
 * {@see \Framework\Routing\ParameterBinder} promises by short-circuiting, and it
 * is the property that stops a half-validated record from being persisted by a
 * controller that assumed it had already been checked.
 *
 * So this probe drives the real binder, with real requests, and asserts on which
 * of the two things came back — the controller's redirect, or the framework's
 * failure response. A spelling mistake in a rule would show up as the *wrong one*
 * of those, which is what makes this worth running rather than reading.
 *
 * Run: php tests/manual/probe-company-store-validation.php
 */

require_once __DIR__ . '/../stubs/wordpress.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../stubs/wp-classes.php';
require_once __DIR__ . '/../stubs/builtins.php';

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Requests\Admin\StoreCompanyRequest;
use Framework\Http\Request;
use Framework\Http\Responses\RedirectResponse;
use Tests\Support\WpStub;

WpStub::reset();

$root = dirname(__DIR__, 2);

$checks = 0;
$failures = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks++;
    printf("%s %s%s\n", $ok ? ' ok ' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
    if (!$ok) {
        $failures[] = $label;
    }
};

app()->instance('path.base', $root);
Framework\Config\Config::load($root . '/config');
(new App\Kernel(app()))->bootstrap();

// Routes, so `redirect()->route('companies.index')` has a name to resolve.
$router = app()->resolve(Framework\Routing\Adapters\WordPressRouterAdapter::class);
(new Framework\Routing\RouteLoader($root))->load($router, 'routes/web.php');
$router->flush();

$controller = app()->resolve(CompanyController::class);
$binder = app()->resolve(Framework\Routing\ParameterBinder::class);

/**
 * Dispatch one submission through the binder, the way the router does.
 *
 * `ParameterSource` is built from the array, so this exercises the same path a
 * real `admin-post.php` submission takes rather than calling the controller
 * directly — calling it directly would bypass the very thing under test.
 *
 * ## How a rejection and an acceptance are told apart
 *
 * **Not by the response type.** Both are redirects: a rejected submission
 * redirects back to the form (a browser cannot render a 422), and an accepted one
 * redirects to the list screen. That is Laravel's own browser behaviour, and a
 * probe that asserted "a rejection is not a redirect" would fail against a
 * framework that is working exactly as designed — which is what the first version
 * of this probe did.
 *
 * The two are told apart by **destination**: the controller's own success
 * redirect is to `page=companies`, and it can only be produced by the controller
 * actually running. The failure redirect goes back to the form. So "did the
 * controller run?" is the question, and the answer is whether the redirect names
 * the list screen.
 */
$dispatch = static function (array $post) use ($controller, $binder): mixed {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $_REQUEST = $post;

    $request = new Request($post, [], [], $_SERVER);

    return $binder->call(
        $controller,
        'store',
        $request,
        new Framework\Routing\Sources\HttpParameterSource($request),
        Request::class
    );
};

/**
 * Whether the controller ran and produced its own success redirect.
 *
 * **The destination cannot answer this**, and that is the trap this probe fell
 * into twice. The form's `redirect_to` field is the *list* screen — the same
 * destination the controller's success redirect names — so a rejected submission
 * and an accepted one both redirect to `page=companies`. Comparing URLs says
 * "the controller ran" for every submission, valid or not.
 *
 * What actually differs is the flash. The controller's own redirect carries
 * `success`; a rejected one carries the validation `errors`, the `old` input and
 * an `error` summary, written by {@see FormRequest}. Only the controller writes
 * `success`, so its presence is proof the method ran.
 */
$controllerRan = static function (mixed $response): bool {
    return $response instanceof RedirectResponse
        && array_key_exists('success', $response->flash());
};

/**
 * The validation errors a rejected submission flashed, if any.
 *
 * @return array<string, list<string>>
 */
$flashedErrors = static function (mixed $response): array {
    if (!$response instanceof RedirectResponse) {
        return [];
    }

    $errors = $response->flash()['errors'] ?? [];

    return is_array($errors) ? $errors : [];
};

// ------------------------------------------------------------- the happy path

$valid = [
    'action' => CompanyController::STORE_ACTION,
    '_wpnonce' => 'stub',
    'redirect_to' => 'http://example.test/wp-admin/admin.php?page=companies',
    'name' => 'Acme Widgets',
    'website' => 'https://acme.test',
    'industry' => 'Manufacturing',
    'phone' => '555-0100',
    'address' => "1 Acme Way\nSpringfield",
];

$response = $dispatch($valid);

$check(
    'a valid submission reaches the controller and redirects',
    $controllerRan($response),
    get_debug_type($response) . ($response instanceof RedirectResponse ? ' -> ' . $response->url() : '')
);

// ------------------------------------------------------- name is the one required

$response = $dispatch(array_merge($valid, ['name' => '']));

$check(
    'a missing name is refused before the controller runs',
    !$controllerRan($response),
    'It reached the controller and redirected — the `required` rule did not fire.'
);

$check(
    'and the refusal blames [name], not some other field',
    array_key_exists('name', $flashedErrors($response)),
    'Flashed: ' . json_encode(array_keys($flashedErrors($response)))
);

$response = $dispatch(array_diff_key($valid, ['name' => null]));

$check(
    'an absent name is refused too (not just an empty one)',
    !$controllerRan($response),
    'An absent field slipped through.'
);

// ------------------------------------------------- optional fields really optional

foreach (['website', 'industry', 'phone', 'address'] as $optional) {
    $response = $dispatch(array_diff_key($valid, [$optional => null]));

    $check(
        sprintf('leaving [%s] out is accepted — it is optional', $optional),
        $controllerRan($response),
        'A company with no ' . $optional . ' was refused.'
    );
}

// ---------------------------------------------------------------- rule specifics

$response = $dispatch(array_merge($valid, ['website' => 'not a url']));

$check(
    'a malformed website is refused by the `url` rule',
    !$controllerRan($response),
    'A non-URL website was accepted, and would be rendered as an <a href> on the list screen.'
);

$check(
    'and the URL refusal blames [website]',
    array_key_exists('website', $flashedErrors($response)),
    'Flashed: ' . json_encode(array_keys($flashedErrors($response)))
);

$response = $dispatch(array_merge($valid, ['name' => str_repeat('a', 256)]));

$check(
    'a name over 255 characters is refused',
    !$controllerRan($response),
    'The `max:255` rule did not fire.'
);

$response = $dispatch(array_merge($valid, ['name' => str_repeat('a', 255)]));

$check(
    'a name of exactly 255 characters is accepted (the boundary is inclusive)',
    $controllerRan($response)
);

// ----------------------------------------------- plumbing is not company data

// The rules name none of these, so `validated()` must not return them. Asserted
// through the FormRequest itself, because the controller does not expose `$data`.
$form = StoreCompanyRequest::fromRequest(new Request($valid, [], [], $_SERVER));
$validated = $form->validated();

foreach (['action', '_wpnonce', 'redirect_to'] as $plumbing) {
    $check(
        sprintf('validated() excludes [%s] — it is form plumbing, not a column', $plumbing),
        !array_key_exists($plumbing, $validated),
        'It was returned, so the controller would treat it as company data.'
    );
}

$check(
    'validated() returns the five company fields and nothing else',
    array_keys($validated) === ['name', 'website', 'industry', 'phone', 'address'],
    'Got: ' . implode(', ', array_keys($validated))
);

printf("\n%d probes, %d failed\n", $checks, count($failures));

if ($failures !== []) {
    foreach ($failures as $failure) {
        printf("  - %s\n", $failure);
    }
    exit(1);
}
