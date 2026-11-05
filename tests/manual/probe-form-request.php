<?php
declare(strict_types=1);

/**
 * Manual probe: FormRequest through the ParameterBinder.
 *
 *     php tests/manual/probe-form-request.php
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/devlinkstudios/wp-service-container/src/Contracts/ContainerInterface.php';

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Http\Request;
use Framework\Http\Requests\FormRequest;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\RedirectResponse;
use Framework\Routing\ParameterBinder;
use Framework\Routing\Sources\HttpParameterSource;

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;

    if (!$condition) {
        $failures++;
    }

    echo ($condition ? "  ok   " : "  FAIL ") . $label . "\n";
}

// -------------------------------------------------------------- fixture

final class StoreLeadRequest extends FormRequest
{
    public bool $ran = false;

    protected function rules(): array
    {
        return [
            'email' => 'required|email|max:255',
            'name' => 'required|string|min:2',
            'age' => 'nullable|integer|between:18,120',
            'tags' => 'array',
            'tags.*' => 'string|max:5',
        ];
    }

    protected function attributes(): array
    {
        return ['email' => 'email address'];
    }

    protected function messages(): array
    {
        return ['email.required' => 'We need your email address.'];
    }
}

final class Controller
{
    public int $calls = 0;

    /** @var array<string, mixed>|null */
    public ?array $received = null;

    public function store(StoreLeadRequest $request): string
    {
        $this->calls++;
        $this->received = $request->validated();

        return 'saved';
    }
}

function container(): Container
{
    $c = new Container();
    $c->instance(ContainerInterface::class, $c);
    $c->instance(Container::class, $c);

    return $c;
}

/** @param array<string, mixed> $body */
function request(array $body, array $headers = []): Request
{
    return new Request(query: [], body: $body, server: ['REQUEST_METHOD' => 'POST'], headers: $headers);
}

function call(string $formClass, Request $req, ?Controller $controller = null): array
{
    $controller ??= new Controller();
    $binder = new ParameterBinder(container());

    $result = $binder->call(
        $controller,
        'store',
        $req,
        new HttpParameterSource($req, []),
        Request::class
    );

    return [$controller, $result];
}

echo "a valid submission reaches the controller\n";
[$c, $r] = call(StoreLeadRequest::class, request(['email' => 'a@b.test', 'name' => 'Ada']));
check('controller was entered', $c->calls === 1);
check('action value returned untouched', $r === 'saved');
check('validated() carries only ruled keys', $c->received === ['email' => 'a@b.test', 'name' => 'Ada']);

echo "\nan invalid submission does NOT reach the controller\n";
[$c, $r] = call(StoreLeadRequest::class, request(['email' => 'nope', 'name' => 'A']));
check('controller was NOT entered', $c->calls === 0);
check('a RedirectResponse came back instead', $r instanceof RedirectResponse);
check('status is 302', $r->status() === 302);

echo "\nthe redirect carries errors and old input\n";
$flash = $r->flash();
check('errors were flashed', isset($flash['errors']['email'], $flash['errors']['name']));
check('a summary message was flashed', isset($flash['error']));
check('old input was flashed', ($flash['old']['name'] ?? null) === 'A');
check('the bad email was preserved for correction', ($flash['old']['email'] ?? null) === 'nope');

echo "\nthe author's message override applies\n";
check('custom email message used', ($flash['errors']['email'][0] ?? '') === 'We need your email address.');

echo "\na JSON client gets a 422 instead of a redirect\n";
[$c, $r] = call(
    StoreLeadRequest::class,
    request(['email' => 'nope'], ['accept' => 'application/json'])
);
check('controller was NOT entered', $c->calls === 0);
check('a JsonResponse came back', $r instanceof JsonResponse);
check('status is 422', $r->status() === 422);
$data = $r->data();
check('error code is validation_failed', ($data['error'] ?? '') === 'validation_failed');
check('errors are keyed by field', isset($data['errors']['email']));

echo "\na JSON *body* also counts as a JSON client\n";
$jsonReq = new Request(
    query: [],
    body: [],
    server: ['REQUEST_METHOD' => 'POST'],
    headers: ['content-type' => 'application/json'],
    rawBody: json_encode(['email' => 'nope'])
);
[$c, $r] = call(StoreLeadRequest::class, $jsonReq);
check('Content-Type alone triggers the 422 path', $r instanceof JsonResponse && $r->status() === 422);

echo "\nvalid data with arrays and wildcards\n";
[$c, $r] = call(StoreLeadRequest::class, request([
    'email' => 'a@b.test',
    'name' => 'Ada',
    'tags' => ['php', 'wp'],
]));
check('wildcard elements accepted', $c->calls === 1 && $r === 'saved');
check('the array survives into validated()', ($c->received['tags'] ?? null) === ['php', 'wp']);

[$c, $r] = call(StoreLeadRequest::class, request([
    'email' => 'a@b.test',
    'name' => 'Ada',
    'tags' => ['php', 'waytoolong'],
]));
check('a bad wildcard element blocks the call', $c->calls === 0 && $r instanceof RedirectResponse);

echo "\nneither domain leaks: the binder clears its short-circuit\n";
$binder = new ParameterBinder(container());
$bad = request(['email' => 'nope', 'name' => 'A']);
$first = $binder->call(new Controller(), 'store', $bad, new HttpParameterSource($bad, []), Request::class);
$good = request(['email' => 'a@b.test', 'name' => 'Ada']);
$second = $binder->call(new Controller(), 'store', $good, new HttpParameterSource($good, []), Request::class);
check('first call short-circuits', $first instanceof RedirectResponse);
check('second call is not poisoned by the first', $second === 'saved');

echo "\na required rule still fires when the field is absent\n";
[$c, $r] = call(StoreLeadRequest::class, request(['email' => 'a@b.test']));
check('absent required name blocks', $c->calls === 0 && isset($r->flash()['errors']['name']));

echo "\n";
echo $failures === 0 ? "all probes passed\n" : "{$failures} probe(s) FAILED\n";

exit($failures === 0 ? 0 : 1);
