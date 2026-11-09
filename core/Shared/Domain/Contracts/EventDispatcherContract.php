<?php

declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

/**
 * Framework-agnostic event dispatcher contract.
 */
interface EventDispatcherContract
{
    /**
     * @param object $event The event object to dispatch.
     */
    public function dispatch(object $event): void;
}