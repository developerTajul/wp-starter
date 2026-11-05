<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Http\Request;

/**
 * Receives the framework Request by type-hint.
 *
 * What it records is where the request came *from*: a controller type-hinting
 * `Request` must receive the request being dispatched — the one the web factory
 * built with the URL parameters folded in — not the superglobal capture taken
 * once at plugin load. Those two agree on the URI and disagree on everything a
 * route matched, which is exactly the difference this records.
 */
final class RequestController
{
    public function index(Request $request): string
    {
        WebRecorder::$calls[] = 'RequestController@index';
        WebRecorder::$received['uri'] = $request->uri();
        WebRecorder::$received['method'] = $request->method();

        return '<p>request</p>';
    }
}
