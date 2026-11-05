<?php
declare(strict_types=1);

namespace Database\Migrations;

use App\Repositories\WpNoteAuditRepository;
use App\Repositories\WpNoteRepository;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migration;
use wpdb;

/**
 * The notes module's schema: the notes table and its audit trail.
 *
 * ## Why both tables are one migration
 *
 * They are one *unit of meaning*: a note and the record of what happened to it
 * are written in the same transaction by `NoteService`, and a schema that could
 * have one without the other is a schema that can hold a note with no history.
 * Splitting them would let an operator apply one and not the other — the state
 * where the service's audit write fails on every create, which is a broken
 * install that reports itself as a *storage* problem.
 *
 * The migrator runs both halves in one file and records one row for them, so
 * "applied" means both tables exist.
 *
 * ## Why the table names come from the repositories
 *
 * `WpNoteRepository::TABLE_SUFFIX` and `WpNoteAuditRepository::TABLE_SUFFIX` are
 * the same constants the queries are built from. A migration that spelled
 * `'notes'` by hand would agree with the repository only until one of them was
 * edited — and the failure would be an "Unknown table" at runtime rather than a
 * mismatch anyone could see by reading either file.
 *
 * ## Why `dbDelta()` is not used
 *
 * It is the WordPress-idiomatic choice and it is the wrong one here. `dbDelta()`
 * diffs the live schema against the CREATE statement on *every* run, so a table
 * it cannot fully express drifts silently — and its parsing rules (two spaces
 * after `PRIMARY KEY`, one field per line, specific key syntax) are strict
 * enough that a mismatch is discovered as a duplicated index rather than an
 * error.
 *
 * A migration already has the thing `dbDelta()` exists to substitute for: a
 * record of what has been applied. Given that, a plain CREATE TABLE is both
 * simpler and more honest — it runs exactly once, and a failure is a failure
 * rather than a diff that decided there was nothing to do.
 */
final class CreateNotesTables extends Migration
{
    /**
     * Table suffixes, read from the repositories that query them.
     */
    private const NOTES_SUFFIX = WpNoteRepository::TABLE_SUFFIX;
    private const AUDIT_SUFFIX = WpNoteAuditRepository::TABLE_SUFFIX;

    /**
     * Migrations are resolved from the container, so the handle is injected
     * rather than reached for through `global $wpdb` — which is what lets this
     * run against a test database without a WordPress install.
     */
    public function __construct(private ?wpdb $wpdb = null)
    {
    }

    public function up(): void
    {
        $db = $this->db();
        $notes = $this->table(self::NOTES_SUFFIX);
        $audit = $this->table(self::AUDIT_SUFFIX);

        // The notes table first: the audit table's foreign key names it, and a
        // constraint cannot reference a table that does not exist yet.
        //
        // `utf8mb4` explicitly rather than inheriting the database default.
        // WordPress's own tables are utf8mb4 and a database created before 5.5
        // defaults to utf8 — which cannot store an emoji in a note body, and
        // fails at *insert* time on exactly the rows a user cares about.
        //
        // `created_at` has no default and is written by the repository, because a
        // note's creation time is the moment the application decided, not the
        // moment MySQL happened to write the row — the two differ inside a
        // transaction that stays open.
        $this->execute(sprintf(
            'CREATE TABLE `%s` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `content` longtext NOT NULL,
                `user_id` bigint(20) unsigned NOT NULL,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `user_id_created_at` (`user_id`, `created_at`)
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $notes
        ), 'create the notes table');

        // The audit trail.
        //
        // `note_id` is NULLABLE, and that is load-bearing rather than
        // incidental. The create path writes its audit entry *inside* the
        // transaction that inserts the note, and it must not wait for the id —
        // an entry written after the insert could commit outside the transaction
        // that performed it, which is the trail that lies. So the entry is
        // written before the id exists, with a null here.
        //
        // `ON DELETE SET NULL` rather than `CASCADE`: deleting a note must not
        // delete the record that it existed. That is the single most important
        // row in the trail — "this note was removed" — and cascading would erase
        // it at exactly the moment it becomes the interesting one.
        //
        // The `id` tiebreak in the read query is why the index is on
        // `(note_id, occurred_at)` and not on `note_id` alone: both columns are
        // in the ORDER BY, and an index that covers only the first leaves MySQL
        // sorting the rest.
        $this->execute(sprintf(
            'CREATE TABLE `%s` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `note_id` bigint(20) unsigned DEFAULT NULL,
                `user_id` bigint(20) unsigned NOT NULL,
                `event` varchar(64) NOT NULL,
                `occurred_at` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `note_id_occurred_at` (`note_id`, `occurred_at`),
                CONSTRAINT `%s_note_fk` FOREIGN KEY (`note_id`)
                    REFERENCES `%s` (`id`) ON DELETE SET NULL
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $audit,
            // Constraint names are database-global in MySQL, so a bare `note_fk`
            // would collide between two installs sharing one database — the same
            // reason the tables are prefixed.
            $audit,
            $notes
        ), 'create the note audit table');
    }

    public function down(): void
    {
        // Audit first — the reverse of `up()`, because the foreign key in it
        // names the other table. Dropping the notes table while a constraint
        // still references it is refused by InnoDB on some versions and silently
        // enforced on others, which is not a difference worth discovering in
        // production.
        $this->execute(
            sprintf('DROP TABLE IF EXISTS `%s`', $this->table(self::AUDIT_SUFFIX)),
            'drop the note audit table'
        );

        $this->execute(
            sprintf('DROP TABLE IF EXISTS `%s`', $this->table(self::NOTES_SUFFIX)),
            'drop the notes table'
        );
    }

    // ------------------------------------------------------------------ internals

    /**
     * The fully-qualified name of a prefixed table.
     */
    private function table(string $suffix): string
    {
        return $this->db()->prefix . $suffix;
    }

    /**
     * Run one DDL statement, reporting the database's own reason on failure.
     *
     * DDL implicitly commits, so there is nothing to roll back and no return
     * value to interpret beyond success or failure — which is why the check is a
     * plain `=== false` here, and why the message has to carry `last_error`.
     * Without it the migrator's own report is "migration failed", which says
     * nothing about *why*.
     */
    private function execute(string $sql, string $operation): void
    {
        $db = $this->db();

        // WordPress 6.7+ can roll back the next failing statement, which cleans
        // up a partially-written DDL. Probed rather than called outright: the
        // plugin's floor is WordPress 6.0, where the method does not exist.
        if (method_exists($db, 'set_transaction_rollback')) {
            $db->set_transaction_rollback(true);
        }

        if ($db->query($sql) === false) {
            throw new MigrationException(sprintf(
                'Could not %s: %s',
                $operation,
                $db->last_error !== '' ? $db->last_error : 'the database reported no error message'
            ));
        }
    }

    /**
     * The WordPress database handle, resolved on first use.
     *
     * Lazy because the migrator constructs migrations during a command's setup,
     * which under WP-CLI runs before `$wpdb` is guaranteed to be the global one.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new MigrationException(
                'No $wpdb instance is available. The notes migration needs a WordPress database '
                . 'handle; pass one to the constructor when running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
