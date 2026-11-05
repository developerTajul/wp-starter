<?php
declare(strict_types=1);

/**
 * Manual verification of the Phase 4.1 wiring against a real WordPress install
 * and a real HTTP server.
 *
 * Not part of the PHPUnit suite, because two of the three things it proves cannot
 * be reached from inside a PHPUnit process:
 *
 *  1. `Request::capture()` reads `php://input`, which no CLI process can seed —
 *     it is only populated by a web SAPI. A test that "sets" the body is testing
 *     a request PHP would never produce, so this drives a real server with curl.
 *  2. `JsonResponse::send()` and `RedirectResponse::send()` both terminate the
 *     request, so the only honest way to observe one is over the wire.
 *  3. The router binding is checked against a real `WP_REST_Server`, so the
 *     callback is the one WordPress would actually call.
 *
 * Run with:  php tests/manual/verify-http-lifecycle.php
 * Starts a server on a free port, uses it, and shuts it down.
 */

define('WP_USE_THEMES', false);
// tests/manual/ -> tests/ -> plugin/ -> plugins/ -> wp-content/ -> install root
require_once dirname(__DIR__, 5) . '/wp-load.php';

$plugin = dirname(__DIR__, 2);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        echo "  ok   {$label}\n";

        return;
    }

    $fail++;
    echo "  FAIL {$label}" . ($detail !== '' ? "  -> {$detail}" : '') . "\n";
}

// ---------------------------------------------------------------- 1. routing

/**
 * A controller whose actions type-hint the framework's Request.
 *
 * Declared before its first use: a class at the top level of a script is only
 * defined when its declaration is *executed*, unlike a function or class inside
 * a conditionally-loaded file, so referring to it earlier is a fatal.
 */
final class VerifyLifecycleController extends Framework\Routing\Controller
{
    public function show(Framework\Http\Request $request): WP_REST_Response
    {
        return $this->respond([
            'id' => $request->input('id'),
            'filter' => $request->query('filter'),
        ]);
    }

    public function both(
        Framework\Http\Request $request,
        WP_REST_Request $native
    ): WP_REST_Response {
        return $this->respond([
            'framework' => $request->input('id'),
            'native' => $native->get_param('id'),
        ]);
    }
}

echo "1. Router binds the REST-hydrated Request for controller type hints\n";

$container = new DevLinkStudios\WPServiceContainer\Container();
$container->instance(
    DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class,
    $container
);
$container->instance(DevLinkStudios\WPServiceContainer\Container::class, $container);

// Stand in for HttpServiceProvider: a capture that knows nothing about the
// request being dispatched, so a passing check cannot be explained by it.
$stale = new Framework\Http\Request(query: ['id' => 'STALE']);
$container->instance(Framework\Http\Request::class, $stale);

$router = new Framework\Routing\Router($container, new Framework\Routing\RouteResolver($container));

// The controller is declared at the bottom of this file, so it cannot be
// autoloaded by name — PHP only hoists it once the script has been compiled, and
// the container resolves by class name. Binding the instance directly is what a
// real project's autoloader would do for it.
$container->instance(VerifyLifecycleController::class, new VerifyLifecycleController());

$router->registrar()->get('leads/{id}', VerifyLifecycleController::class, 'show');
$router->registrar()->get('leads/{id}/both', VerifyLifecycleController::class, 'both');

add_action('rest_api_init', static fn () => $router->registerRoutes('verify-lifecycle/v1'));
do_action('rest_api_init');

$server = rest_get_server();
$routes = $server->get_routes();

$findCallback = static function (string $exactPathFragment) use ($routes): ?callable {
    foreach ($routes as $path => $handlers) {
        // Anchored at the end so `/leads/(?P<id>[^/]+)` cannot also match
        // `/leads/(?P<id>[^/]+)/both` — a substring test picks whichever route
        // the server happened to list first, which is how this silently probed
        // the wrong handler.
        if (!str_ends_with($path, $exactPathFragment)) {
            continue;
        }

        foreach ($handlers as $handler) {
            if (isset($handler['callback'])) {
                return $handler['callback'];
            }
        }
    }

    return null;
};

/**
 * Call a route callback and report a WP_Error as what it is, rather than
 * letting get_data() fatal on it — an error here is a finding, not a crash.
 */
$call = static function (callable $callback, WP_REST_Request $request): array {
    $result = $callback($request);

    if ($result instanceof WP_Error) {
        return ['error' => $result->get_error_message()];
    }

    return (array) $result->get_data();
};

$show = $findCallback('leads/(?P<id>[^/]+)');
check('the route reached WordPress', $show !== null);

if ($show !== null) {
    $rest = new WP_REST_Request('GET', '/verify-lifecycle/v1/leads/123');
    $rest->set_url_params(['id' => '123']);
    $rest->set_query_params(['filter' => 'recent']);

    $data = $call($show, $rest);

    check(
        'a controller type-hinting Framework\Http\Request receives the URL params',
        ($data['id'] ?? null) === '123',
        json_encode($data)
    );
    check(
        'and the query string',
        ($data['filter'] ?? null) === 'recent',
        json_encode($data)
    );
    check(
        'and NOT the stale superglobal capture',
        ($data['id'] ?? null) !== 'STALE',
        json_encode($data)
    );
}

$both = $findCallback('both');
if ($both !== null) {
    $rest = new WP_REST_Request('GET', '/verify-lifecycle/v1/leads/9/both');
    $rest->set_url_params(['id' => '9']);
    $data = $call($both, $rest);

    check(
        'a controller hinting the native WP_REST_Request still works, and both agree',
        ($data['framework'] ?? null) === '9' && ($data['native'] ?? null) === '9',
        json_encode($data)
    );
}

check(
    'the pre-existing binding is restored after the dispatch',
    $container->resolve(Framework\Http\Request::class)->input('id') === 'STALE'
);

// ------------------------------------------------- 2/3. real HTTP lifecycle

echo "\n2. capture(), and the response send() methods, over real HTTP\n";

$port = random_int(8900, 9400);
$docroot = sys_get_temp_dir() . '/wpmini-lifecycle-' . bin2hex(random_bytes(4));
mkdir($docroot, 0755, true);

// The endpoint reports what capture() saw, and can be asked to emit a real
// JsonResponse or RedirectResponse — which end the request, so the only way to
// observe them is as the client sees them.
//
// WordPress is loaded so wp_send_json() and wp_safe_redirect() are the real
// functions. Without it both responses correctly refuse to send, which is the
// guard working rather than the response being exercised.
$endpoint = <<<'PHP'
<?php
declare(strict_types=1);

// WP_USE_THEMES=false keeps the front-end template out of the way; capture()
// reads the real superglobals the web SAPI populated.
define('WP_USE_THEMES', false);
require INSTALL_ROOT . '/wp-load.php';
require PLUGIN . '/vendor/autoload.php';

use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\RedirectResponse;

$request = Request::capture();

if (($_GET['send'] ?? '') === 'json') {
    // Terminates the request itself, via wp_send_json().
    (new JsonResponse(['ok' => true, 'path' => $request->uri()], ['status_code' => 201]))->send();
}

if (($_GET['send'] ?? '') === 'redirect') {
    // Also terminates, via wp_safe_redirect() followed by exit.
    (new RedirectResponse('/landed', 302, $request))->send();
}

header('Content-Type: application/json');
echo json_encode([
    'method' => $request->method(),
    'uri' => $request->uri(),
    'email' => $request->input('email'),
    'plan' => $request->input('meta')['plan'] ?? null,
    'isJson' => $request->isJson(),
    'filter' => $request->input('filter'),
    'isSecure' => $request->isSecure(),
    'host' => $request->header('host'),
]);
PHP;

$installRoot = dirname(__DIR__, 5);
$endpoint = str_replace(
    ['INSTALL_ROOT', 'PLUGIN'],
    [var_export($installRoot, true), var_export($plugin, true)],
    $endpoint
);
file_put_contents($docroot . '/index.php', $endpoint);

$descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
$process = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot],
    $descriptors,
    $pipes
);

if (!is_resource($process)) {
    echo "  FAIL could not start the test server\n";
    exit(1);
}

// Wait for the server to accept a connection rather than sleeping a fixed time.
$ready = false;
for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

    if ($socket !== false) {
        fclose($socket);
        $ready = true;
        break;
    }

    usleep(100_000);
}

$get = static function (string $url, array $headers = [], ?string $body = null, string $method = 'GET'): array {
    $headerLines = [];

    foreach ($headers as $name => $value) {
        $headerLines[] = "{$name}: {$value}";
    }

    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headerLines),
        'content' => $body ?? '',
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 5,
    ]]);

    $response = @file_get_contents($url, false, $context);

    return [
        'body' => $response === false ? '' : $response,
        'headers' => $http_response_header ?? [],
    ];
};

if (!$ready) {
    echo "  FAIL the test server never came up\n";
    proc_terminate($process);
    exit(1);
}

$base = 'http://127.0.0.1:' . $port;

// --- capture() on a real browser-shaped GET
$result = $get($base . '/leads?filter=recent', ['Accept' => 'text/html']);
$json = json_decode($result['body'], true);

check(
    'capture() reads the method and URI a browser sends',
    ($json['method'] ?? null) === 'GET' && ($json['uri'] ?? null) === '/leads?filter=recent',
    $result['body']
);
check(
    'capture() parses the query string',
    ($json['filter'] ?? null) === 'recent',
    $result['body']
);
check(
    'capture() reads the Host header',
    is_string($json['host'] ?? null) && str_contains((string) $json['host'], '127.0.0.1'),
    $result['body']
);

// --- capture() on a real JSON POST, the case no CLI process can produce
$result = $get(
    $base . '/leads',
    ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    '{"email":"a@b.test","meta":{"plan":"pro"}}',
    'POST'
);
$json = json_decode($result['body'], true);

check(
    'capture() parses a real application/json body',
    ($json['email'] ?? null) === 'a@b.test',
    $result['body']
);
check(
    'capture() reads nested JSON values',
    ($json['plan'] ?? null) === 'pro',
    $result['body']
);
check(
    'capture() reports isJson() for a real JSON request',
    ($json['isJson'] ?? null) === true,
    $result['body']
);

// --- capture() behind a proxy
$result = $get($base . '/leads', ['X-Forwarded-Proto' => 'https']);
$json = json_decode($result['body'], true);
check(
    'capture() honours X-Forwarded-Proto',
    ($json['isSecure'] ?? null) === true,
    $result['body']
);

// --- a real form post
$result = $get(
    $base . '/leads',
    ['Content-Type' => 'application/x-www-form-urlencoded'],
    'email=form@b.test&filter=form',
    'POST'
);
$json = json_decode($result['body'], true);
check(
    'capture() parses a real form body',
    ($json['email'] ?? null) === 'form@b.test',
    $result['body']
);

// --- JsonResponse::send() over the wire
$result = $get($base . '/?send=json');
$json = json_decode($result['body'], true);
$statusLine = $result['headers'][0] ?? '';
$contentType = '';

foreach ($result['headers'] as $header) {
    if (stripos($header, 'content-type:') === 0) {
        $contentType = $header;
    }
}

check(
    'JsonResponse::send() emits the JSON body',
    ($json['ok'] ?? null) === true,
    $result['body']
);
check(
    'JsonResponse::send() emits the status code',
    str_contains($statusLine, '201'),
    $statusLine
);
check(
    'JsonResponse::send() emits a JSON content type',
    stripos($contentType, 'application/json') !== false,
    $contentType
);
check(
    'JsonResponse::send() terminates the request',
    !str_contains($result['body'], '"method"'),
    'the endpoint continued past send()'
);

// --- RedirectResponse::send() over the wire
$result = $get($base . '/?send=redirect');
$statusLine = $result['headers'][0] ?? '';
$location = '';

foreach ($result['headers'] as $header) {
    if (stripos($header, 'location:') === 0) {
        $location = $header;
    }
}

check(
    'RedirectResponse::send() emits a 302',
    str_contains($statusLine, '302'),
    $statusLine
);
check(
    'RedirectResponse::send() emits the Location header',
    stripos($location, '/landed') !== false,
    $location
);
check(
    'RedirectResponse::send() terminates the request',
    !str_contains($result['body'], '"method"'),
    'the endpoint continued past send()'
);

// --- teardown
proc_terminate($process);
proc_close($process);
@unlink($docroot . '/index.php');
@rmdir($docroot);

echo "\n" . ($fail === 0 ? "ALL {$pass} CHECKS PASSED" : "{$fail} FAILED, {$pass} passed") . "\n";

exit($fail === 0 ? 0 : 1);
