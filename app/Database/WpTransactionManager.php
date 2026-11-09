<?php

declare(strict_types=1);

namespace App\Database;

use Core\Shared\Domain\Contracts\TransactionManagerContract;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * Transaction manager for WordPress `$wpdb`.
 *
 * Implements nested transaction handling by counting depth. The outermost
 * call starts a real MySQL transaction; inner calls join it. Savepoints are
 * not used to keep the implementation simple and portable across MySQL
 * versions and storage engines.
 *
 * @implements TransactionManagerContract
 */
final class WpTransactionManager implements TransactionManagerContract
{
    /**
     * Current nesting depth.
     */
    private int $depth = 0;

    /**
     * @param wpdb|null $wpdb Database handle; null to resolve the global.
     */
    public function __construct(private ?wpdb $wpdb = null)
    {
    }

    /**
     * Return the current depth (useful for tests).
     */
    public function depth(): int
    {
        return $this->depth;
    }

    public function transaction(callable $callback): mixed
    {
        return $this->run($callback);
    }

    /**
     * Execute a callback within a transaction, joining if already inside one.
     */
    public function run(callable $callback): mixed
    {
        $db = $this->db();

        // Join an existing transaction.
        if ($this->depth > 0) {
            return $callback();
        }

        $this->beginTransaction();
        $this->depth++;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $this->depth--;
            $db->query('ROLLBACK');
            throw $e;
        }

        $this->depth--;
        $this->commit();
        return $result;
    }

    /**
     * Start a MySQL transaction (only called for the outermost frame).
     */
    public function beginTransaction(): void
    {
        $this->db()->query('START TRANSACTION');
    }

    /**
     * Commit the transaction (only called for the outermost frame).
     */
    public function commit(): void
    {
        $this->db()->query('COMMIT');
    }

    /**
     * Whether a transaction is currently open.
     */
    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Roll back the open transaction and reset depth.
     */
    public function rollback(): void
    {
        if ($this->depth === 0) {
            return;
        }
        $this->depth = 0;
        $this->db()->query('ROLLBACK');
    }

    /**
     * Resolve the WordPress `$wpdb` handle lazily.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }
        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new RuntimeException(
                'No $wpdb instance is available. WpTransactionManager needs a WordPress database handle; pass one to the constructor when running outside a loaded WordPress.'
            );
        }
        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
