<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Database;

use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Illuminate\Support\Facades\DB;

/**
 * ADAPTER: Laravel implementation of the transaction boundary port.
 * Canonical home for shared DB-level structural adapters.
 */
final class LaravelTransactionManager implements TransactionManagerContract
{
    public function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    public function commit(): void
    {
        DB::commit();
    }

    public function rollback(): void
    {
        DB::rollBack();
    }

    /**
     * Runs the callback in a transaction and returns its value.
     *
     * The value is handed back untouched rather than going through
     * DB::transaction(), which casts whatever the callback returns to a bool.
     * That cast would destroy a Result's message and error code, and would
     * return null for a use case whose success path has to hand a persisted
     * entity back to the caller.
     *
     * @param callable $callback
     *
     * @return mixed
     *
     * @throws \Throwable The callback's own exception, rethrown unchanged.
     */
    public function run(callable $callback): mixed
    {
        DB::beginTransaction();

        try {
            $result = $callback();

            DB::commit();

            return $result;
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }
}
