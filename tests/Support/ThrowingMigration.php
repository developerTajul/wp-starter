<?php
declare(strict_types=1);

namespace Tests\Support;

use Framework\Database\Migration;
use RuntimeException;

/**
 * A migration whose up() and down() both throw.
 *
 * The exception is a plain RuntimeException rather than a MigrationException, so
 * a test can prove the migrator wraps an arbitrary throwable from user code
 * instead of only recognising its own. The message is fixed and asserted on, so
 * the `previous` chain is checked for content rather than merely for existence.
 */
final class ThrowingMigration extends Migration
{
    public const MESSAGE = 'The table could not be created.';

    public function up(): void
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function down(): void
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
