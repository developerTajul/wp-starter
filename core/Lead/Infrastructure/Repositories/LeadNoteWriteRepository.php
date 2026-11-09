<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

use Core\Lead\Domain\Contracts\LeadNoteRepositoryContract;
use Core\Lead\Domain\Entities\LeadNoteEntity;
use Core\Lead\Infrastructure\Mappers\LeadNoteEntityMapper;
use RuntimeException;
use wpdb;

/**
 * `$wpdb`-backed writes for lead notes.
 *
 * Deliberately not Eloquent: this is a WordPress plugin, so `BaseRepository` and
 * the Eloquent classes it needs ship in a Laravel `vendor/` that is not present.
 * The class would be unreachable at best and a fatal `Error` at worst, in a file
 * Composer's PSR-4 map still compiles. Its sibling {@see LeadWriteRepository}
 * answers the same contract over `$wpdb`.
 *
 * The `$wpdb` resolution, identifier escaping and failure reporting shared with
 * {@see LeadWriteRepository} are not extracted into a base class: it would be a
 * shared ancestor the read repository inherits without needing it, which is the
 * widening that made `BaseRepository` unusable here.
 *
 * @implements LeadNoteRepositoryContract
 */
final class LeadNoteWriteRepository implements LeadNoteRepositoryContract
{
    /** Table suffix without the prefix; the installation prefix is applied by {@see getTableName()}. */
    public const TABLE_SUFFIX = 'lead_notes';

    /**
     * @param LeadNoteEntityMapper $mapper
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily; see {@see db()}. Optional so the container can build this during `register()`, before `$wpdb` is loaded.
     */
    public function __construct(
        private readonly LeadNoteEntityMapper $mapper,
        private ?wpdb $wpdb = null,
    ) {
    }

    /**
     * The fully-qualified table name.
     *
     * Public because a seeder or an admin screen may need the same name, and
     * re-deriving it from the prefix and the constant at each of those sites is
     * how the two drift apart.
     */
    public function getTableName(): string
    {
        return $this->db()->prefix . self::TABLE_SUFFIX;
    }

    /**
     * {@inheritDoc}
     *
     * `created_at` and `updated_at` are written explicitly rather than left to
     * the column defaults, for the same reason {@see LeadWriteRepository} does
     * it: `CURRENT_TIMESTAMP` is evaluated in the *database's* timezone while
     * every value this module writes is UTC, so a row written by the default and
     * a row written by this class would be hours apart while both claimed to be
     * UTC.
     */
    public function create(LeadNoteEntity $note): LeadNoteEntity
    {
        $db = $this->db();

        $now = $this->now();

        $columns = [
            'lead_id' => $note->getLeadId(),
            'body' => $note->getBody(),
            'is_pinned' => $note->isPinned() ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $ok = $db->insert($this->getTableName(), $columns, $this->formatsFor($columns));

        if ($ok === false) {
            throw $this->queryFailed('store a new lead note', $db->last_error);
        }

        $id = (int) $db->insert_id;

        // A note returned without its id would be INSERTed again on its next
        // write, silently duplicating the row — so a driver that did not report
        // one is a failure here rather than a null the caller has to notice.
        if ($id <= 0) {
            throw new RuntimeException(
                'The lead note was inserted but no id was reported. A note returned without its id '
                . 'would be inserted again on its next write, silently duplicating the row.'
            );
        }

        return $this->readBack($id, 'read back the lead note it just created');
    }

    /**
     * {@inheritDoc}
     *
     * `FOR UPDATE` needs a transaction to mean anything. Read outside one, MySQL
     * releases the lock as soon as the statement's implicit transaction ends, and
     * the call becomes an ordinary read that merely looks safe — which is worse
     * than not locking, because the code reads as if it were protected. The
     * caller takes this inside the transaction that is about to write.
     */
    public function findByIdForUpdate(int $id): ?LeadNoteEntity
    {
        $db = $this->db();

        $sql = $db->prepare(
            sprintf(
                'SELECT * FROM `%s` WHERE `id` = %%d FOR UPDATE',
                $this->escapeIdentifier($this->getTableName())
            ),
            $id
        );

        $row = $db->get_row($sql, ARRAY_A);

        if ($row === null) {
            return null;
        }

        return $this->mapper->toEntity($row);
    }

    /**
     * {@inheritDoc}
     *
     * Returns the stored row rather than the entity it was handed, so the caller
     * maps post-write values. The read-back is what makes the returned
     * `updated_at` the database's answer rather than this process's guess at it.
     */
    public function savePinState(LeadNoteEntity $note): LeadNoteEntity
    {
        $noteId = $note->getId();

        if ($noteId === null) {
            throw new RuntimeException(
                'A note cannot be pinned before it has been stored. An UPDATE with no id matches no '
                . 'row and would report a write that never happened.'
            );
        }

        $db = $this->db();

        $columns = [
            'is_pinned' => $note->isPinned() ? 1 : 0,
            'updated_at' => $this->now(),
        ];

        $affected = $db->update(
            $this->getTableName(),
            $columns,
            ['id' => $noteId],
            $this->formatsFor($columns),
            ['%d']
        );

        if ($affected === false) {
            throw $this->queryFailed(sprintf('update the pin state of lead note %d', $noteId), $db->last_error);
        }

        // A zero affected count is *not* a failure: MySQL reports zero rows
        // changed when the UPDATE matched a row and every value was already what
        // it was given — pinning an already-pinned note, which is the ordinary
        // case for a double click. What must not be inferred from it is absence;
        // that is asked separately below.
        $stored = $this->readBack($noteId, 'read back the lead note whose pin state it just changed');

        return $stored;
    }

    /**
     * Read a row back by id and map it, failing loudly when it is gone.
     *
     * Reading back rather than returning the entity the caller passed means the
     * caller receives the timestamps the write actually produced. The failure
     * case is real: the row can be deleted between the load and the write, and
     * returning an entity that no longer corresponds to anything is worse than a
     * raise, since upstream has already matched the row and believes it exists.
     */
    private function readBack(int $id, string $action): LeadNoteEntity
    {
        $db = $this->db();

        $sql = $db->prepare(
            sprintf(
                'SELECT * FROM `%s` WHERE `id` = %%d',
                $this->escapeIdentifier($this->getTableName())
            ),
            $id
        );

        $row = $db->get_row($sql, ARRAY_A);

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'Lead note %d could not be read back after a write. The row disappeared during the '
                . 'save, so the write and this read no longer describe the same record.',
                $id
            ));
        }

        return $this->mapper->toEntity($row);
    }

    /**
     * The `$wpdb` format specifier for each column being written.
     *
     * Derived from the values, since the failure is asymmetric: an int written as
     * `%s` is coerced silently, but a string written as `%d` becomes `0` and the
     * body is lost.
     *
     * The arms mirror {@see LeadWriteRepository::formatsFor()}, including the
     * explicit `null` case so a float column added later gets `%f` rather than
     * being stringified.
     *
     * @param array<string, mixed> $columns
     *
     * @return list<string>
     */
    private function formatsFor(array $columns): array
    {
        return array_map(
            static fn (mixed $value): string => match (true) {
                $value === null => '%s',
                is_int($value) => '%d',
                is_float($value) => '%f',
                default => '%s',
            },
            array_values($columns)
        );
    }

    /**
     * Escape an identifier's backticks, without adding the quotes.
     *
     * The caller supplies the backticks, as in the `sprintf` templates above.
     * Names come from the prefix and a constant rather than from input, but a
     * prefix containing a backtick would end the identifier early and name a
     * different table, and `$wpdb` offers no helper for this.
     */
    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * The current instant as a UTC `DATETIME` string.
     *
     * Not `current_time()`, which returns the site's local time; every timestamp
     * this module writes is UTC, and mixing the two only shows up on a site whose
     * timezone is not UTC.
     */
    private function now(): string
    {
        return (new \DateTimeImmutable('now'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * The WordPress database handle, resolved on first use.
     *
     * Lazy rather than injected, because WordPress owns this object: a
     * constructor type-hint on `wpdb` would resolve through the container's
     * auto-wiring to a freshly constructed, unconnected instance rather than the
     * site's global one. Resolving here means a class built during `register()`
     * does not fail before WordPress has set `$wpdb` up.
     *
     * @throws RuntimeException When there is no handle to resolve.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new RuntimeException(
                'No $wpdb instance is available. LeadNoteWriteRepository needs a WordPress database '
                . 'handle; pass one to the constructor when running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }

    /**
     * A failure that names the operation rather than only the driver's message.
     *
     * `$wpdb->last_error` is often empty — a statement can fail without the
     * driver populating it — so the action is what makes the report useful, and
     * it is carried on a `RuntimeException` rather than logged, so it reaches
     * whoever is debugging rather than a log file nobody opened.
     */
    private function queryFailed(string $action, string $error): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Failed to %s. The database reported: %s',
            $action,
            $error === '' ? '(no error message)' : $error
        ));
    }
}
