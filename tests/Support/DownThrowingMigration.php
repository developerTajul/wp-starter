<?php
declare(strict_types=1);

namespace Tests\Support;

use Framework\Database\Migration;
use RuntimeException;

/**
 * A migration that applies cleanly and fails only when reversed.
 *
 * {@see ThrowingMigration} throws in both directions, which makes it useless for
 * testing a *rollback* failure: it can never be applied in the first place, so
 * there is nothing recorded to roll back. This one separates the two, which is
 * the state a real project reaches — a migration that ran months ago and whose
 * `down()` has since broken against a schema that moved on.
 *
 * That is the case worth covering, because it is the case a `down()` failure is
 * actually made of. The exception is a plain RuntimeException for the same reason
 * as its sibling: it proves the migrator wraps an arbitrary throwable from user
 * code rather than only recognising its own.
 */
final class DownThrowingMigration extends Migration
{
    public const MESSAGE = 'The table could not be dropped.';

    public function up(): void
    {
        // Deliberately empty: the migration has to be applicable for its
        // rollback to be reachable.
    }

    public function down(): void
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
