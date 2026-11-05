<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * Returns nothing, which a web route cannot send.
 *
 * A controller written for a REST endpoint often returns nothing after setting a
 * header or writing to a model, and that habit produces a blank page on a web
 * route — indistinguishable from a broken plugin. The adapter names it instead.
 */
final class VoidController
{
    public function index(): void
    {
        WebRecorder::$calls[] = 'VoidController@index';
    }
}
