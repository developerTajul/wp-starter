<?php
declare(strict_types=1);

namespace Tests\Support;

use Framework\Database\Migration;

/**
 * A migration that succeeds and records that it ran.
 *
 * Used as a template: {@see MigrationWorkspace} reads this file, renames the
 * class to match whichever filename it is being written to, and leaves the body
 * alone. A static log is used rather than instance state because each migration
 * is constructed fresh by the migrator, so an instance property would be lost
 * between `up()` and any later assertion.
 *
 * The `down()` body records separately from `up()`, which is what lets a test
 * assert that rollback called the right direction rather than merely that
 * something happened.
 */
final class RecordingMigration extends Migration
{
    /**
     * Identifiers (class names) whose up() ran, in order.
     *
     * @var list<string>
     */
    public static array $applied = [];

    /**
     * Identifiers whose down() ran, in order.
     *
     * @var list<string>
     */
    public static array $reverted = [];

    public static function reset(): void
    {
        self::$applied = [];
        self::$reverted = [];
    }

    public function up(): void
    {
        self::$applied[] = static::class;
    }

    public function down(): void
    {
        self::$reverted[] = static::class;
    }
}
