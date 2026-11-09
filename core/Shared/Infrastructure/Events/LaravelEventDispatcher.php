<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Events;

use Core\Shared\Domain\Contracts\EventDispatcherContract;

final class LaravelEventDispatcher implements EventDispatcherContract
{
    public function dispatch(object $event): void
    {
        event($event);
    }
}