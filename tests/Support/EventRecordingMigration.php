<?php
declare(strict_types=1);

namespace Tests\Support;

use Framework\Database\Migration;

/**
 * A migration that leaves a marker as it runs, so hook *order* is assertable.
 *
 * {@see RecordingMigration} issues no SQL and leaves no trace of having run, so
 * a test built on it can only ask whether a hook fired — never whether it fired
 * on the right side of the migration body. That is the one thing an
 * event-ordering test exists to check: a `migration_starting` hook that fired
 * *after* `up()` would satisfy every presence assertion and still be useless to
 * the listener it exists for (one that wants to prepare something first).
 *
 * ## Why the marker goes into the hook recorder, not a static here
 *
 * The obvious design is a `public static array $trail` on this class, appended to
 * by `up()` and read by the test. It does not work, and the reason is worth
 * stating: {@see MigrationWorkspace::write()} does not run this class — it copies
 * this file's *source* into a per-workspace namespace under a new class name, so
 * the migration that actually runs is a different class with a different static.
 * A test appending to this class's `$trail` would be appending to a variable the
 * migration never touches, and would read back a list containing only its own
 * hooks — "up" silently missing, the exact assertion that was meant to catch a
 * mis-ordered hook failing for an unrelated reason.
 *
 * So the marker goes into the process-wide WordPress recorder instead, which is
 * the same store the hook callbacks write to and is reachable from the copied
 * class with no shared static at all. The tag is deliberately not a WordPress
 * action name; it is a sentinel that exists only to be found in the same ordered
 * list as the hooks.
 */
final class EventRecordingMigration extends Migration
{
    /**
     * The marker `up()` leaves in {@see \Tests\Support\WpStub::$fired}.
     */
    public const UP_MARKER = 'migration-body:up';

    /**
     * The marker `down()` leaves, distinguishable so a rollback test can tell
     * which direction was running when a hook fired.
     */
    public const DOWN_MARKER = 'migration-body:down';

    public function up(): void
    {
        // Fully qualified, not imported: the workspace copies this file's source
        // into a per-workspace namespace and rewrites the `namespace` line but not
        // the `use` statements, so a short `WpStub` would resolve to a class in the
        // *generated* namespace and never be found. The leading backslash is what
        // makes the copy reach the real recorder.
        \Tests\Support\WpStub::$fired[] = self::UP_MARKER;
    }

    public function down(): void
    {
        \Tests\Support\WpStub::$fired[] = self::DOWN_MARKER;
    }
}
