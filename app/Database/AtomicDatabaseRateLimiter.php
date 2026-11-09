<?php

declare(strict_types=1);

namespace App\Database;

use Core\Shared\Domain\Contracts\RateLimiterContract;
use Core\Shared\RateLimitResult;
use RuntimeException;
use wpdb;

/**
 * Atomic database‑backed rate limiter.
 *
 * Performs a single INSERT … ON DUPLICATE KEY UPDATE statement to
 * increment the counter atomically, eliminating the race condition of a
 * read‑modify‑write pattern. The row stores the attempt count and an
 * absolute expiration timestamp; the window is fixed and restarts when
 * expired.
 *
 * Does not use WordPress transients because they are not atomic and may be
 * stored in non‑persistent caches.
 *
 * @implements RateLimiterContract
 */
final class AtomicDatabaseRateLimiter implements RateLimiterContract
{
    /**
     * WordPress database handle, resolved lazily from the global $wpdb.
     */
    private ?wpdb $wpdb;

    /**
     * @param wpdb|null $wpdb   Database handle; null to resolve the global.
     * @param string|null $table Fully‑qualified table name; defaults to the
     *                           prefixed table created by the migration.
     */
    public function __construct(?wpdb $wpdb = null, private readonly ?string $table = null)
    {
        $this->wpdb = $wpdb;
    }

    public function hit(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult
    {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException(sprintf(
                'A rate limit must allow at least one attempt; %d was given. A limit of zero would refuse every request, which is a broken route rather than a rate limit.',
                $maxAttempts
            ));
        }

        if ($decaySeconds < 1) {
            throw new \InvalidArgumentException(sprintf(
                'A rate‑limit window must be at least one second; %d was given. A zero‑second window resets immediately, which is a limiter that never limits.',
                $decaySeconds
            ));
        }

        $db = $this->db();
        $table = $this->table();
        $now = time();
        $expiresAt = $now + $decaySeconds;

        // Hash the key to keep the stored value a fixed width and avoid
        // exposing raw data.
        $hashed = hash('sha256', $key);

        // One statement: INSERT … ON DUPLICATE KEY UPDATE. The increment
        // happens server‑side with no read performed by this process.
        $sql = $db->prepare(
            "INSERT INTO {$table} (rate_key, attempts, expires_at) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE attempts = IF(expires_at <= %d, 1, attempts + 1), expires_at = IF(expires_at <= %d, %d, expires_at)",
            $hashed,
            $expiresAt,
            $now,
            $now,
            $expiresAt
        );
        $db->query($sql);

        // Retrieve the counter and remaining seconds after the atomic
        // increment. The decision is already made based on $maxAttempts.
        [$attempts, $secondsUntilReset] = $this->readBack($db, $table, $hashed, $now, $expiresAt);

        return $attempts <= $maxAttempts
            ? RateLimitResult::allowed($attempts, $maxAttempts, $secondsUntilReset)
            : RateLimitResult::denied($attempts, $maxAttempts, $secondsUntilReset);
    }

    public function clear(string $key): void
    {
        $db = $this->db();
        // Delete the row to start a fresh window on the next hit.
        $db->query(
            $db->prepare(
                sprintf('DELETE FROM %s WHERE rate_key = %%s', $this->table()),
                hash('sha256', $key)
            )
        );
    }

    /**
     * Returns [attempts, secondsUntilReset] after the atomic increment.
     *
     * @return array{0: int, 1: int}
     */
    private function readBack(wpdb $db, string $table, string $hashed, int $now, int $expiresAt): array
    {
        $row = $db->get_row(
            $db->prepare("SELECT attempts, expires_at FROM {$table} WHERE rate_key = %s", $hashed),
            ARRAY_A
        );

        if (!is_array($row)) {
            throw new RuntimeException(
                'The rate limiter incremented a key but could not read it back. The limiter table is most likely missing; run the migration that creates it.'
            );
        }

        $attempts = (int) ($row['attempts'] ?? 0);
        $storedExpiry = (int) ($row['expires_at'] ?? $expiresAt);
        $secondsUntilReset = max(0, $storedExpiry - $now);
        $secondsUntilReset = min($secondsUntilReset, $expiresAt - $now);
        return [$attempts, $secondsUntilReset];
    }

    /**
     * Fully‑qualified table name.
     */
    private function table(): string
    {
        if ($this->table !== null) {
            return $this->table;
        }
        $db = $this->db();
        return $db->prefix . 'mini_rate_limits';
    }

    /**
     * Resolve the WordPress database handle lazily.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }
        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new RuntimeException(
                'No $wpdb instance is available. AtomicDatabaseRateLimiter needs a WordPress database handle; pass one to the constructor when running outside a loaded WordPress.'
            );
        }
        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
