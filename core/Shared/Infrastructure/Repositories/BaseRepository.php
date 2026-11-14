<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Repositories;

use Framework\Database\Query\Exceptions\UniqueConstraintException;
use Framework\Database\Query\QueryBuilderInterface;
use RuntimeException;
use Throwable;

/**
 * Shared write helpers for module repositories that use the Fluent Database
 * builder. Infrastructure only; entities are mapped by each module's own mapper.
 */
abstract class BaseRepository
{
    public function __construct(
        protected readonly QueryBuilderInterface $query,
    ) {}

    /**
     * Runs a write and translates its failures.
     *
     * A unique-index violation is rethrown as the module's conflict exception,
     * resolved through {@see duplicateException()}. Every other failure keeps
     * its own type, so a caller cannot mistake an outage for a duplicate.
     *
     * @template T
     *
     * @param string      $action    Phrased for the failure message.
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws RuntimeException When the write fails.
     */
    protected function executeQuery(string $action, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintException $exception) {
            throw $this->duplicateException($exception);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('Failed to %s: %s', $action, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * The module's own conflict exception for a rejected unique index.
     *
     * Modules override this to name their exception, which keeps this shared
     * base from importing a module it must not know about.
     *
     * @param UniqueConstraintException $exception
     *
     * @return Throwable
     */
    protected function duplicateException(UniqueConstraintException $exception): Throwable
    {
        return $exception;
    }

    /**
     * Inserts a row and returns its new id.
     *
     * @param array<string, mixed> $values
     * @param string               $table  The table name, without the WordPress prefix.
     * @param string               $action Phrased for the failure message.
     *
     * @return int
     *
     * @throws RuntimeException When the insert fails.
     */
    protected function insert(array $values, string $table, string $action = 'insert'): int
    {
        return $this->executeQuery($action, fn(): int => $this->query->table($table)->insertGetId($values));
    }

    /**
     * Updates one row and confirms the row was there.
     *
     * The builder reports rows changed rather than rows matched, so a write of
     * the values already stored returns 0 without anything being wrong. The row
     * is confirmed with a separate existence check.
     *
     * @param int                  $id
     * @param array<string, mixed> $values
     * @param string               $table  The table name, without the WordPress prefix.
     * @param string               $action Phrased for the failure message.
     *
     * @return void
     *
     * @throws RuntimeException When the row does not exist or the write fails.
     */
    protected function write(int $id, array $values, string $table, string $action): void
    {
        $changed = $this->executeQuery(
            $action,
            fn(): int => $this->query->table($table)->where('id', $id)->update($values)
        );

        if ($changed === 0 && ! $this->rowExists($id, $table)) {
            throw new RuntimeException(sprintf('Failed to %s: row with ID %d does not exist', $action, $id));
        }
    }

    /**
     * Whether a row with the given id exists.
     *
     * @param int    $id
     * @param string $table The table name, without the WordPress prefix.
     *
     * @return bool
     */
    protected function rowExists(int $id, string $table): bool
    {
        return $this->query->table($table)->where('id', $id)->count() > 0;
    }

    /**
     * The current time as the UTC string the datetime columns store.
     *
     * @return string
     */
    protected function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
