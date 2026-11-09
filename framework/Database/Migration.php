<?php
declare(strict_types=1);

namespace Framework\Database;

/**
 * Base class for a migration.
 *
 * A migration is one reversible step in the schema's history, so the two methods
 * are mirror images of each other: whatever `up()` creates, `down()` must remove,
 * and vice versa. A `down()` that does not undo its `up()` makes `rollback()`
 * destructive rather than restorative, which is only discovered when someone
 * actually needs it.
 *
 * Migrations are resolved from the container, so one may declare constructor
 * dependencies - a `$wpdb`, a schema builder, a seeder - rather than reaching for
 * `global $wpdb` and making itself untestable.
 *
 * A migration's own `up()`/`down()` failures are the migrator's to report; it
 * catches `Throwable` and stops the batch. There is deliberately no silent
 * "nothing to do" path here: a migration whose `up()` returns without doing
 * anything is recorded as ran, which is a decision the author makes explicitly.
 */
abstract class Migration
{
    /**
     * Apply the migration.
     */
    abstract public function up(): void;

    /**
     * Reverse the migration.
     *
     * Called by {@see Migrator::rollback()}, so it must leave the database in
     * the state it was in before `up()` ran.
     */
    abstract public function down(): void;
}
