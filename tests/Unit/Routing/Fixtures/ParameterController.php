<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * Receives a route placeholder as a typed parameter.
 *
 * The parameter is typed `string`, so it is only filled if the placeholder the
 * URL matched was actually handed to the binder. A controller whose parameter
 * silently kept a default would look identical from the outside, which is why
 * the recorded value is asserted rather than the fact that the action ran.
 */
final class ParameterController
{
    public function show(string $id): string
    {
        WebRecorder::$calls[] = 'ParameterController@show';
        WebRecorder::$received['id'] = $id;

        return '<p>' . $id . '</p>';
    }
}
