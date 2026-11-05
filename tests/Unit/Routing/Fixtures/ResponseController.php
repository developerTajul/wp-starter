<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Responses\HtmlResponse;

/**
 * Returns an explicit ResponseInterface — the other shape a web action may use.
 *
 * The non-200 status matters: it proves the dispatcher sends what the controller
 * built rather than constructing a response of its own.
 */
final class ResponseController
{
    public function index(): ResponseInterface
    {
        WebRecorder::$calls[] = 'ResponseController@index';

        return new HtmlResponse('<p>explicit</p>', 201);
    }
}
