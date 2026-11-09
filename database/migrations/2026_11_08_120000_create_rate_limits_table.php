<?php
declare(strict_types=1);

namespace Database\Migrations;

use App\Database\AtomicDatabaseRateLimiter;
use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

/**
 * The rate limiter's counters.
 *
 * ## Why a table, when WordPress already has transients
 *
 * Transients are the obvious place for a short-lived counter and they are the
 * wrong one here, for a reason that is about correctness rather than preference.
 *
 * A rate limiter must increment and decide **atomically**. Transients offer
 * `get_transient()` and `set_transient()` and nothing in between, so the only
 * available implementation is a read, a compare and a write — and two concurrent
 * requests both reading `9` against a limit of `10` both decide "allowed". The
 * limit is bypassed by sending *more* traffic, which is the opposite of what it
 * is for. {@see AtomicDatabaseRateLimiter} carries the full argument.
 *
 * That is unfixable at the transient layer, and with a persistent object cache —
 * Redis, Memcached — the counters are not even in the database, so there is no
 * lock available to take. A table is what provides a real atomic primitive:
 * `INSERT … ON DUPLICATE KEY UPDATE`.
 *
 * ## The unique index is the entire mechanism
 *
 * `ON DUPLICATE KEY UPDATE` needs a unique key to detect the duplicate *against*.
 * Without `uniq_rate_key` the statement inserts a new row on every hit, no
 * duplicate is ever detected, and every request is the first attempt in its
 * window — a limiter that always allows, with a table that grows without bound
 * until the database fills. This is the one column in this migration that is
 * load-bearing rather than merely useful.
 *
 * `rate_key` is a `VARCHAR(64)`, which is exactly a hex-encoded `sha256`. The
 * limiter hashes before storing, so the column never holds an email address, an
 * IP or a user agent — see the limiter for why — and a fixed-width index stays
 * dense, which a `TEXT` index would not.
 *
 * ## Why there is no foreign key
 *
 * Nothing to point at. A key may be scoped to an IP, which is not a row; to a
 * user, which is; or to a composite the caller invented. A foreign key would
 * have to pick one and would be wrong for the other two, and the cascade would
 * delete counters — silently resetting a limit — as a side effect of unrelated
 * housekeeping.
 *
 * ## Cleanup
 *
 * Expired rows are left in place. They are inert — the limiter's own statement
 * resets an expired row in place rather than being confused by it — and deleting
 * them is a scheduled job this framework does not have. Leaving them is the right
 * default: a few thousand dead rows is a smaller problem than a limiter that
 * deletes rows a concurrent request is about to read, and `clear()` removes the
 * ones that matter.
 */
final class CreateRateLimitsTable extends Migration
{
    /**
     * The table's suffix.
     *
     * A constant on the migration rather than on the limiter, because the limiter
     * derives its name from `$wpdb->prefix` at runtime and this file is what
     * actually creates the table — the two must agree, and stating it here makes
     * that agreement checkable by reading one file. The limiter's constructor
     * takes the name if a project needs to override it.
     */
    private const SUFFIX = 'mini_rate_limits';

    public function up(): void
    {
        Schema::create(self::SUFFIX, static function (Blueprint $table): void {
            $table->id();

            // The hashed key. `VARCHAR(64)` is a hex `sha256` exactly, and the
            // unique index below is what makes the limiter's single statement
            // atomic — see the class docblock.
            $table->string('rate_key', 64)->unique();

            // How many attempts the current window holds.
            $table->unsignedInteger('attempts')->default(0);

            // When the window rolls over, as a Unix timestamp.
            //
            // An integer rather than a `datetime`: the limiter compares it against
            // `time()` and sends the difference as `Retry-After` seconds, so a
            // datetime would mean converting on every read and would make the
            // comparison sensitive to the database session's timezone — a limiter
            // that misbehaves when the server's timezone is not UTC.
            $table->unsignedInteger('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::SUFFIX);
    }
}
