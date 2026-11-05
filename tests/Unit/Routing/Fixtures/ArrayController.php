<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * Returns an array — the REST idiom, which a web route must refuse.
 *
 * Not merely unhelpful: `echo` on an array emits the literal text "Array" with a
 * 200 status, which looks like a rendering bug rather than a wrong return type.
 * The adapter names the mistake and points at `routes/api.php`.
 */
final class ArrayController
{
    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        WebRecorder::$calls[] = 'ArrayController@index';

        return ['ok' => true];
    }
}
