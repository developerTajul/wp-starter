<?php
declare(strict_types=1);

namespace Database\Migrations;

use App\Repositories\WpNoteAuditRepository;
use App\Repositories\WpNoteRepository;
use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

/**
 * The note audit trail: what happened *to* a note.
 *
 * ## Why this is its own migration
 *
 * It was not, originally — this table and the one in
 * `..._120000_create_notes_table.php` were created by a single `up()`, on the
 * reasoning that a note and its history are one unit of meaning. The domain
 * claim is true; the migration claim was not. See the notes migration's
 * docblock for the failure a two-table `up()` produces and why one table per
 * migration removes it.
 *
 * What the split costs is that "applied" no longer means "both tables exist".
 * It means each table exists if and only if its own migration is recorded —
 * which is a *stronger* guarantee for the audit trail, because a note with no
 * history is now the state the migrator can actually detect and resume from,
 * rather than a state it reports as fully applied.
 *
 * The timestamp (`120001`) sorts after the notes migration's (`120000`), and it
 * has to: the foreign key below names that table, so applying this one first
 * would fail on a table that does not exist yet. Ordering by filename is the
 * only ordering the migrator applies, so the dependency is carried in the name.
 *
 * ## Why the table names come from the repositories
 *
 * `WpNoteAuditRepository::TABLE_SUFFIX` and `WpNoteRepository::TABLE_SUFFIX` are
 * the same constants the queries are built from. A migration that spelled
 * `'note_audit'` by hand would agree with the repository only until one of them
 * was edited — and the failure would be an "Unknown table" at runtime rather
 * than a mismatch anyone could see by reading either file.
 *
 * ## Why this goes through `Schema::create()`
 *
 * For the same reason as the notes migration, and the sibling's docblock carries
 * the full argument: a plain `CREATE TABLE` cannot survive being replayed
 * against a live schema, and `dbDelta()` is what makes it diff instead of fail.
 * Both notes migrations were rewritten together when a live run showed the
 * failure.
 *
 * The foreign key is the one part of this table `dbDelta()` could not have
 * carried itself — its field parser reads a `CONSTRAINT` line as a column — so
 * the adapter lifts the constraint out of the CREATE and applies it afterwards.
 * That path is idempotent, which is what keeps *this* migration replayable: the
 * key is added only if `information_schema` does not already report it.
 */
final class CreateNoteAuditTable extends Migration
{
    /**
     * The suffix of this table, read from the repository that queries it.
     */
    private const SUFFIX = WpNoteAuditRepository::TABLE_SUFFIX;

    /**
     * The table this one's foreign key names.
     */
    private const NOTES_SUFFIX = WpNoteRepository::TABLE_SUFFIX;

    public function up(): void
    {
        Schema::create(self::SUFFIX, static function (Blueprint $table): void {
            $table->id();

            // NULLABLE, and that is load-bearing rather than incidental. The
            // create path writes its audit entry *inside* the transaction that
            // inserts the note, and it must not wait for the id — an entry
            // written after the insert could commit outside the transaction that
            // performed it, which is the trail that lies. So the entry is written
            // before the id exists, carrying a null here.
            //
            // `SET NULL` rather than `CASCADE`: deleting a note must not delete
            // the record that it existed. That is the single most important row
            // in the trail — "this note was removed" — and cascading would erase
            // it at exactly the moment it becomes the interesting one.
            $table->foreignId('note_id')
                ->nullable()
                ->constrained(self::NOTES_SUFFIX)
                ->nullOnDelete();

            $table->foreignId('user_id');
            $table->string('event', 64);
            $table->datetime('occurred_at');

            // Not `timestamps()`: an audit entry records when the event happened,
            // which is `occurred_at`, and a separate `updated_at` on a row that is
            // never updated would be a column that only ever repeats it.
            //
            // The `id` tiebreak in the read query is why the index is on
            // `(note_id, occurred_at)` and not on `note_id` alone: both columns
            // are in the ORDER BY, and an index covering only the first leaves
            // MySQL sorting the rest.
            $table->index(['note_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        // Drops only this migration's table. It holds the foreign key, so it has
        // to go before the notes table — and the migrator's reverse order
        // guarantees that when a batch is rolled back.
        Schema::dropIfExists(self::SUFFIX);
    }
}
