<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * Carries a non-public action, which is a route-definition mistake.
 *
 * The binder refuses it with a message naming the method, rather than letting
 * reflection raise an obscure error or — worse — a container `call()` fail with
 * "method does not exist" for a method that plainly does.
 */
final class PrivateActionController
{
    public function index(): string
    {
        WebRecorder::$calls[] = 'PrivateActionController@index';

        return '<p>public</p>';
    }

    protected function secret(): string
    {
        return '<p>secret</p>';
    }
}
