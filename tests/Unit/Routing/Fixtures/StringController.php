<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * Returns a plain string, which the dispatcher wraps in an HtmlResponse.
 *
 * This is the shape a view-less web action naturally produces, and the one the
 * adapter has to accept rather than refuse — a controller that has built its
 * markup has nothing else to return.
 */
final class StringController
{
    public function index(): string
    {
        WebRecorder::$calls[] = 'StringController@index';

        return '<p>index</p>';
    }
}
