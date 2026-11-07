<?php
declare(strict_types=1);

namespace Database\Migrations;

use App\Repositories\WpNoteRepository;
use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

/**
 * The notes table: what a note *is*.
 *
 * ## Why this is its own migration
 *
 * It was not, originally. This file and `..._120001_create_note_audit_table.php`
 * were one migration that created both tables in a single `up()`, on the
 * reasoning that a note and its history are one unit of meaning. That reasoning
 * is sound about the *domain* and wrong about the *migrator*.
 *
 * The migrator records a migration only after its `up()` returns. So a `up()`
 * that writes two tables has a window — between the first `CREATE` and the
 * second — where the first table exists and nothing records that it does. An
 * interruption there (a failed second `CREATE`, a killed process, a dropped
 * connection) leaves a schema the migrator believes is untouched. Every re-run
 * then replays `up()` from the top and dies on the duplicate:
 *
 *     Table 'leadbridge_notes' already exists
 *
 * That is not hypothetical — it is what a live MySQL 5.7.33 run produced when
 * the tracking table was dropped and the migrations were replayed against an
 * intact schema. One table per migration removes the window entirely: the unit
 * the migrator records is the unit the database changes.
 *
 * The ordering still carries the domain relationship, and now enforces it: the
 * audit table's foreign key names this table, and this migration's timestamp
 * (`120000`) sorts before the audit's (`120001`), so the migrator can never
 * apply them in the wrong order.
 *
 * ## Why the table name comes from the repository
 *
 * `WpNoteRepository::TABLE_SUFFIX` is the same constant the queries are built
 * from. A migration that spelled `'notes'` by hand would agree with the
 * repository only until one of them was edited — and the failure would be an
 * "Unknown table" at runtime rather than a mismatch anyone could see by reading
 * either file.
 *
 * ## Why this goes through `Schema::create()`, having once argued against it
 *
 * This migration used to issue a plain `CREATE TABLE` through `$wpdb`, with a
 * docblock arguing that a migration already has the record `dbDelta()` exists to
 * substitute for, so the diffing helper was redundant. That argument was wrong,
 * and a live run is what showed it.
 *
 * The reasoning assumed the tracking table is always correct. It is not — it can
 * be dropped, restored from an older backup, or lost in a partial restore, and
 * any of those replays every `up()` against a schema that is already there. A
 * plain CREATE cannot survive that: it fails on the first table that exists, and
 * because the migrator records a migration only after `up()` returns, the run
 * stops with the schema *ahead* of the record. That is exactly what happened:
 *
 *     Table 'leadbridge_notes' already exists
 *
 * `dbDelta()` is what makes the statement survive being replayed — it diffs a
 * live table instead of re-creating it. The other ten migrations here always got
 * that for free by going through `Schema::create()`; these two were the only
 * ones that opted out, and they were the only two that could not be replayed.
 *
 * The cost the old docblock was right about is real: `dbDelta()` is line-oriented
 * and strict, and a statement it misparses drifts silently rather than failing.
 * That cost is paid in one place — `WpDatabaseAdapter::toDbDeltaStatement()`,
 * which normalises the statement for it — and paying it once is much cheaper than
 * a schema that cannot be replayed.
 */
final class CreateNotesTable extends Migration
{
    /**
     * The table suffix, read from the repository that queries it.
     */
    private const SUFFIX = WpNoteRepository::TABLE_SUFFIX;

    public function up(): void
    {
        // The charset is not stated here: `Schema::` takes it from the adapter,
        // which asks WordPress for the collation its own tables use. Naming
        // `utf8mb4` by hand would override a database whose default WordPress
        // deliberately matched to something else.
        Schema::create(self::SUFFIX, static function (Blueprint $table): void {
            $table->id();

            // 255 rather than the blueprint's 191 default: `title` is not
            // indexed, so the single-column index limit that sets 191 does not
            // apply to it, and a title longer than 191 characters is a note
            // someone typed rather than a schema mistake.
            $table->string('title', 255);

            // `longtext`, matching the column the repository writes. `text()`
            // emits longtext rather than MySQL's `text` on purpose — the two are
            // the same type to a reader and differ by a factor of 256 at the
            // point where a note body would be truncated.
            $table->text('content');

            // `foreignId()` without `constrained()`: the key is declared by the
            // audit migration's side, and adding one here would be a second
            // constraint on the same column. The column type still has to match
            // what it references, which `foreignId()` guarantees.
            $table->foreignId('user_id');

            // No default and no `useCurrent()`: a note's creation time is the
            // moment the application decided, not the moment MySQL happened to
            // write the row. The two differ inside a transaction that stays open,
            // which is precisely when an audit trail matters.
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Drops only this migration's table. The audit table belongs to
        // `..._120001_create_note_audit_table.php` and that migration's `down()`
        // drops it — each migration reversing its own work is the whole point of
        // the split, and reaching across would leave the audit migration recorded
        // as applied with no table behind it.
        //
        // The migrator reverses a batch newest-first, so the audit table is gone
        // by the time this runs. `dropIfExists()`, because a rollback of a
        // migration that never ran is the state a partly-applied batch leaves
        // behind, and it is exactly when someone reaches for `migrate:rollback`.
        Schema::dropIfExists(self::SUFFIX);
    }
}
