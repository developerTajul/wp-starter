<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * Declares a `bool` parameter, to prove the binder's coercion runs on the web
 * path as well as the REST one.
 *
 * The failure it pins down is specific and silent: a form posting
 * `published=false` delivers the non-empty string `"false"`, and PHP coerces a
 * non-empty string to `true` — so without the binder's normalisation the
 * controller publishes the record while reporting that it did not. A form is a
 * *more* likely source of that spelling than a JSON body, which makes this the
 * domain where the bug is easiest to hit.
 */
final class BoolController
{
    public function update(bool $published): string
    {
        WebRecorder::$calls[] = 'BoolController@update';
        WebRecorder::$received['published'] = $published;

        return '<p>' . ($published ? 'yes' : 'no') . '</p>';
    }
}
