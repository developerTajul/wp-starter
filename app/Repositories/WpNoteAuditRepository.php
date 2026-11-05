<?php
declare(strict_types=1);

namespace App\Repositories;

use Core\Note\Domain\NoteAuditContract;
use Core\Note\Domain\NoteAuditEntry;
use RuntimeException;
use wpdb;

/**
 * The note audit trail, on `$wpdb`.
 *
 * ## What this class is, and what it is not
 *
 * The storage adapter behind {@see NoteAuditContract} — the one place that knows
 * the trail is a MySQL table rather than a log file, a syslog stream, or an
 * append to an S3 object. A project that wants a different sink writes a second
 * implementation and changes one binding in
 * {@see \App\Providers\RepositoryServiceProvider}; nothing above it moves.
 *
 * It is deliberately the mirror of {@see WpNoteRepository}: same handle seam,
 * same failure reporting, same escaping rule. Two adapters that disagree about
 * how they reach the database would be two things to debug when one of them
 * stops working under a persistent process.
 *
 * ## Why it does not manage transactions
 *
 * It must not, and the temptation is worth naming. `record()` is called from
 * inside the transaction that performs the change, so a write that commits
 * independently could outlive a rollback — a durable record of an event that
 * never happened. The contract forbids an implementation from opening its own
 * unit of work, and this one has no transaction code at all: it issues the
 * INSERT and lets whatever frame is already open decide its fate.
 *
 * ## Why a failed write raises instead of being swallowed
 *
 * This is the one place where "return quietly" would be actively harmful. An
 * audit trail whose writes can silently fail is a trail with holes that nothing
 * reports — and a missing entry is indistinguishable from an event that never
 * occurred. Raising means the surrounding transaction rolls back, so the change
 * the entry describes does not happen either. The trail stays truthful by losing
 * the operation rather than the record of it.
 *
 * ## The table name
 *
 * `{$wpdb->prefix}note_audit`, built from the prefix and a constant and never
 * interpolated from user input, escaped for backticks through
 * {@see escapeIdentifier()} for the same reason the note repository does it: no
 * caller should have to reason about whether a particular identifier is safe.
 */
final class WpNoteAuditRepository implements NoteAuditContract
{
    /**
     * Table suffix, appended to `$wpdb->prefix`.
     */
    public const TABLE_SUFFIX = 'note_audit';

    /**
     * `$wpdb`'s "return an associative array" flag, named so the intent reads at
     * the call site.
     */
    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * @param wpdb|null $wpdb WordPress database handle. Defaults to the `$wpdb`
     *                         global, resolved lazily so the repository can be
     *                         constructed during `register()` — before WordPress
     *                         has necessarily finished loading — and injected in
     *                         tests without touching a global.
     */
    public function __construct(private ?wpdb $wpdb = null)
    {
    }

    /**
     * The fully-qualified table name.
     *
     * Public because a migration or an admin screen may need the same name, and
     * re-deriving it at each of those sites is how the two drift apart.
     */
    public function getTableName(): string
    {
        return $this->db()->prefix . self::TABLE_SUFFIX;
    }

    // ------------------------------------------------------------------ writing

    public function record(NoteAuditEntry $entry): void
    {
        $db = $this->db();

        // `insert()` rather than a hand-written INSERT, so the column/value
        // escaping is WordPress's rather than this class's. It reports failure by
        // returning false and never throws, which is checked below.
        //
        // ## Why `note_id` is formatted `%s` and not `%d`
        //
        // This is the one column that can be null, and the format string decides
        // what null becomes. `%d` runs the value through `(int)`, so null is
        // written as **0** — an entry about a note that may genuinely exist,
        // silently attached to it. WordPress's `%s` quotes the value, and its
        // `insert()` special-cases a null *value* to the bare SQL keyword `NULL`
        // before the format is consulted, which is what leaves a real null in the
        // column.
        //
        // The format is per-column and it is the only place this is decided, so
        // getting it wrong here has no second chance elsewhere.
        $ok = $db->insert(
            $this->getTableName(),
            [
                // Null on the create path — see NoteAuditEntry. The column is
                // nullable rather than the entry being written late, because an
                // entry written after the insert could commit outside the
                // transaction that performed it.
                'note_id' => $entry->noteId,
                'user_id' => $entry->userId,
                'event' => $entry->event,
                'occurred_at' => $this->formatDate($entry->occurredAt),
            ],
            ['%s', '%d', '%s', '%s']
        );

        if ($ok === false) {
            throw $this->queryFailed(
                sprintf('record the [%s] audit entry', $entry->event),
                $db->last_error
            );
        }
    }

    // ------------------------------------------------------------------ reading

    /**
     * @return list<NoteAuditEntry>
     */
    public function forNote(int $noteId): array
    {
        $db = $this->db();

        // Oldest first, which is the order a trail is read in. `id` is the
        // tiebreak rather than `occurred_at` alone, because two entries written
        // in the same second would otherwise come back in an order the database
        // is free to change between calls — and a trail that reorders itself is
        // a trail a reader cannot cite.
        $rows = $db->get_results($db->prepare(
            sprintf(
                'SELECT * FROM `%s` WHERE `note_id` = %%d ORDER BY `occurred_at` ASC, `id` ASC',
                $this->escapeIdentifier($this->getTableName())
            ),
            $noteId
        ), self::OUTPUT_ASSOC);

        // get_results() returns an empty array for no rows *and* on a query
        // error, so last_error is what separates "nothing happened to this note"
        // from "the statement was broken". Reading a failure as an empty trail
        // would report that a note has no history when the real answer is that
        // the database is unreachable.
        if (!is_array($rows)) {
            throw $this->queryFailed(sprintf('read the audit trail for note %d', $noteId), $db->last_error);
        }

        if ($rows === [] && $db->last_error !== '') {
            throw $this->queryFailed(sprintf('read the audit trail for note %d', $noteId), $db->last_error);
        }

        return array_map(fn (array $row): NoteAuditEntry => $this->hydrate($row), $rows);
    }

    // ------------------------------------------------------------------ internals

    /**
     * Map a raw row onto a domain entry.
     *
     * {@see NoteAuditEntry::reconstitute()} rather than `record()`: the row came
     * out of storage, so its values are trusted. The distinction is the same one
     * {@see WpNoteRepository::hydrate()} makes, and for the same reason — a rule
     * added since would otherwise reject historical entries.
     *
     * `note_id` is read as nullable. A row written on the create path carries
     * null there, and casting it with `(int)` would turn "this entry predates the
     * note's id" into "this entry is about note zero".
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NoteAuditEntry
    {
        return NoteAuditEntry::reconstitute(
            isset($row['note_id']) ? (int) $row['note_id'] : null,
            (int) $row['user_id'],
            (string) $row['event'],
            $this->parseDate($row['occurred_at'] ?? null)
        );
    }

    /**
     * A `DateTimeImmutable` from a MySQL `datetime` column value.
     *
     * MySQL hands back a string with no timezone, and this class stores UTC. The
     * zone is stated explicitly rather than taken from the process default, so
     * the same row is the same instant wherever PHP happens to be running.
     */
    private function parseDate(mixed $value): \DateTimeImmutable
    {
        $string = is_string($value) ? trim($value) : '';

        if ($string === '') {
            // A row whose timestamp cannot be read is a schema problem, not an
            // entry without a time. Refusing beats inventing "now", which would
            // make an old event look like it just happened.
            throw new RuntimeException(
                'A stored audit entry has no usable timestamp. Every entry carries an occurred_at, '
                . 'so a blank one means the column is missing or was written as an empty string.'
            );
        }

        try {
            return new \DateTimeImmutable($string, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new RuntimeException(
                sprintf('A stored audit entry carries an unparseable timestamp [%s].', $string),
                0,
                $e
            );
        }
    }

    /**
     * A `DateTimeImmutable` as MySQL's `datetime` format, in UTC.
     */
    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * A statement failed, with the database's own explanation.
     */
    private function queryFailed(string $operation, string $error): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Could not %s: %s',
            $operation,
            $error !== '' ? $error : 'the database reported no error message'
        ));
    }

    /**
     * The WordPress database handle, resolved on first use.
     *
     * Lazy for the same reason {@see WpNoteRepository::db()} is: a provider's
     * `register()` runs before WordPress has necessarily finished setting `$wpdb`
     * up.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new RuntimeException(
                'No $wpdb instance is available. WpNoteAuditRepository needs a WordPress database '
                . 'handle; pass one to the constructor when running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }

    /**
     * Make a table name safe to interpolate inside backticks.
     *
     * `prepare()` quotes *values*; a quoted table name is a syntax error, so the
     * identifier is escaped here instead. The name is built from `$wpdb->prefix`
     * and a constant, so this is belt-and-braces rather than a live injection
     * path — it exists so that no caller has to reason about that.
     */
    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }
}
