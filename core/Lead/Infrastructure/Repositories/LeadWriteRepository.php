<?php
declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

use Core\Lead\Domain\Contracts\LeadRepositoryContract;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\Exceptions\LeadDuplicateException;
use Core\Lead\Infrastructure\Mappers\LeadEntityMapper;
use RuntimeException;
use wpdb;

/**
 * `$wpdb`-backed writes for leads.
 *
 * Holds the writes plus `findByIdForUpdate()`, the row lock taken before a status
 * change; every other read lives on {@see \Core\Lead\Application\Contracts\LeadQueryContract}.
 *
 * `update()`, `updateStatus()` and `updateOwner()` each write a disjoint set of
 * columns, so a status change cannot overwrite an owner.
 *
 * `email` and `phone` carry unique indexes. A collision raises SQLSTATE 23000,
 * which `create()` and `update()` translate into {@see LeadDuplicateException};
 * translating every failure would report a dropped connection as a duplicate
 * email.
 *
 * Writes return the stored entity, so callers see post-write values.
 *
 * @implements LeadRepositoryContract
 */
final class LeadWriteRepository implements LeadRepositoryContract
{
    /** Table suffix appended to `$wpdb->prefix`; matches {@see LeadReadRepository::TABLE_SUFFIX}. */
    public const TABLE_SUFFIX = 'leads';

    /**
     * SQLSTATE MySQL reports for an integrity violation.
     *
     * Covers foreign keys as well as unique keys. Narrowing to the unique index
     * would mean parsing MySQL's message text, which is localised.
     */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * @param LeadEntityMapper $mapper Array to entity translation.
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily; see {@see db()}.
     */
    public function __construct(
        private readonly LeadEntityMapper $mapper,
        private ?wpdb $wpdb = null,
    ) {}

    /**
     * The fully-qualified table name.
     */
    public function getTableName(): string
    {
        return $this->db()->prefix . self::TABLE_SUFFIX;
    }

    // ------------------------------------------------------------------ writes

    /**
     * {@inheritDoc}
     *
     * The returned entity carries the assigned id, so a caller can update the
     * row it just created instead of inserting a second copy.
     */
    public function create(LeadEntity $lead): LeadEntity
    {
        $db = $this->db();

        $columns = $this->writableColumns($lead);
        $now = $this->now();

        // Written explicitly rather than left to the column defaults, because
        // `CURRENT_TIMESTAMP` is evaluated in the database's timezone while
        // every other value in this module is UTC.
        $columns['created_at'] = $now;
        $columns['updated_at'] = $now;

        $ok = $db->insert($this->getTableName(), $columns, $this->formatsFor($columns));

        if ($ok === false) {
            $this->throwIfDuplicate($db->last_error, 'store a new lead');

            throw $this->queryFailed('store a new lead', $db->last_error);
        }

        $id = (int) $db->insert_id;

        // An entity returned without an id would be inserted again on its next
        // write, silently duplicating the row.
        if ($id <= 0) {
            throw new RuntimeException(
                'The lead was inserted but no id was reported. A lead returned without its id '
                . 'would be inserted again on its next write, silently duplicating the row.'
            );
        }

        return $this->readBack($id, 'read back the lead it just created');
    }

    /**
     * {@inheritDoc}
     *
     * The caller takes this lock inside the transaction that is about to change
     * the status. Without it, two requests that read the same lead and each
     * decide from the status they saw could both write, and the second would
     * win.
     *
     * `FOR UPDATE` needs a transaction to mean anything. Read outside one, MySQL
     * releases the lock as soon as the statement's implicit transaction ends,
     * and the call becomes an ordinary read that looks safe without being so.
     */
    public function findByIdForUpdate(int $id): ?LeadEntity
    {
        $db = $this->db();

        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `id` = %%d LIMIT 1 FOR UPDATE',
            $this->escapeIdentifier($this->getTableName())
        );

        $row = $db->get_row($db->prepare($sql, $id), self::OUTPUT_ASSOC);

        if ($row === null && $db->last_error !== '') {
            throw $this->queryFailed(sprintf('lock lead %d for update', $id), $db->last_error);
        }

        return is_array($row) ? $this->mapper->toEntity($row) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function updateStatus(LeadEntity $lead): LeadEntity
    {
        $id = $this->storedId($lead);

        $this->writeColumns($id, [
            'status' => $lead->getStatus()->value,
        ], 'update the status of lead %d');

        return $this->readBack($id, 'read back the lead whose status it just changed');
    }

    /**
     * {@inheritDoc}
     */
    public function updateOwner(LeadEntity $lead): LeadEntity
    {
        $id = $this->storedId($lead);

        $this->writeColumns($id, [
            'assigned_to' => $lead->getAssignedTo(),
        ], 'update the owner of lead %d');

        return $this->readBack($id, 'read back the lead whose owner it just changed');
    }

    /**
     * {@inheritDoc}
     *
     * Writes the columns an edit form owns. Status and owner are absent on
     * purpose and each has its own method.
     */
    public function update(LeadEntity $lead): LeadEntity
    {
        $id = $this->storedId($lead);

        $this->writeColumns($id, [
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'company_name' => $lead->companyName,
            'source' => $lead->source,
        ], 'update lead %d');

        return $this->readBack($id, 'read back the lead it just updated');
    }

    /**
     * {@inheritDoc}
     *
     * The contract returns void, and a delete that removed nothing is not the
     * same as one that removed a lead: the caller has already loaded the lead,
     * so a zero-row delete means something removed it in between.
     */
    public function delete(int $id): void
    {
        $db = $this->db();

        $affected = $db->query($db->prepare(
            sprintf('DELETE FROM `%s` WHERE `id` = %%d', $this->escapeIdentifier($this->getTableName())),
            $id
        ));

        if ($affected === false) {
            $this->throwIfDuplicate($db->last_error, 'delete a lead');

            throw $this->queryFailed(sprintf('delete lead %d', $id), $db->last_error);
        }

        if ($affected < 1) {
            throw new RuntimeException(sprintf(
                'Lead %d was not deleted because no row carries that id. A delete of a lead the '
                . 'caller has loaded means the row was removed in between.',
                $id
            ));
        }
    }

    // ------------------------------------------------------------------ internals

    /**
     * The columns a new lead is inserted with.
     *
     * Delegates to the mapper so the insert and the read-back agree on names.
     * See {@see LeadEntityMapper::toPersistenceArray()}.
     *
     * @return array<string, mixed>
     */
    private function writableColumns(LeadEntity $lead): array
    {
        $columns = $this->mapper->toPersistenceArray($lead);

        // Added here rather than by the mapper, which produces the columns a
        // form owns. A lead created already assigned, by an importer, still
        // needs the owner set on insert.
        $columns['assigned_to'] = $lead->getAssignedTo();

        return $columns;
    }

    /**
     * Run an UPDATE over one row and fail loudly if it matched nothing.
     *
     * @param array<string, mixed> $columns
     * @param string               $action A sprintf template taking the id.
     */
    private function writeColumns(int $id, array $columns, string $action): void
    {
        $db = $this->db();

        $columns['updated_at'] = $this->now();

        $ok = $db->update(
            $this->getTableName(),
            $columns,
            ['id' => $id],
            $this->formatsFor($columns),
            ['%d']
        );

        if ($ok === false) {
            $this->throwIfDuplicate($db->last_error, sprintf($action, $id));

            throw $this->queryFailed(sprintf($action, $id), $db->last_error);
        }

        // `$wpdb->update()` returns the number of rows changed, not matched, so
        // a write that set every column to the value it already held reports 0.
        // That is a successful no-op rather than a missing row, and treating it
        // as an error would make re-saving an unedited lead fail.
        if ($ok === 0 && !$this->rowExists($id)) {
            throw new RuntimeException(sprintf(
                'Lead %d no longer exists: the update matched no row. It was removed between the '
                . 'read that produced this entity and the write.',
                $id
            ));
        }
    }

    /**
     * Whether a row with this id is stored.
     *
     * Only called after an UPDATE reported zero changed rows, to tell "unchanged"
     * from "gone".
     */
    private function rowExists(int $id): bool
    {
        $db = $this->db();

        $count = $db->get_var($db->prepare(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `id` = %%d', $this->escapeIdentifier($this->getTableName())),
            $id
        ));

        if ($count === null && $db->last_error !== '') {
            throw $this->queryFailed(sprintf('check whether lead %d still exists', $id), $db->last_error);
        }

        return (int) $count > 0;
    }

    /**
     * Re-read a row after writing it, so the caller keeps stored values.
     *
     * Every write returns through here for one reason: the database owns the
     * timestamps and the id, and an entity assembled from the caller's own values
     * would be missing both. `$lead->updatedAt` on an entity that has never been
     * stored is the empty string, and a service that returned that to a client
     * would be reporting a timestamp that does not exist.
     */
    private function readBack(int $id, string $action): LeadEntity
    {
        $db = $this->db();

        $row = $db->get_row($db->prepare(
            sprintf(
                'SELECT * FROM `%s` WHERE `id` = %%d LIMIT 1',
                $this->escapeIdentifier($this->getTableName())
            ),
            $id
        ), self::OUTPUT_ASSOC);

        if ($row === null && $db->last_error !== '') {
            throw $this->queryFailed($action, $db->last_error);
        }

        if (!is_array($row)) {
            throw new RuntimeException(sprintf(
                'Lead %d could not be read back after being written. The row was inserted or '
                . 'updated and then not found, which means it was removed by something else '
                . 'inside the same request.',
                $id
            ));
        }

        return $this->mapper->toEntity($row);
    }

    /**
     * The id of a lead that is already stored.
     *
     * The guard is what makes the assumption checkable. Every write except
     * `create()` names a row, and an entity with no id cannot — it would produce
     * `WHERE id = 0` and update nothing, or worse, match a row that happened to
     * carry a zero id.
     */
    private function storedId(LeadEntity $lead): int
    {
        if ($lead->id === null) {
            throw new RuntimeException(
                'A write other than create() was given a lead with no id. An unsaved lead cannot '
                . 'name the row to update; it has to be created first.'
            );
        }

        return $lead->id;
    }

    /**
     * `$wpdb` format specifiers matching a column map, in the same order.
     *
     * Built from the values rather than declared alongside the columns, because
     * the two must stay index-aligned: `$wpdb->insert()` pairs the nth format
     * with the nth value, so a format list that drifted by one would bind an
     * integer as a string — silently, since MySQL coerces it — and the bug would
     * surface only as an index that stopped being used.
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
     * `$wpdb`'s error message when it reports a duplicate key, or null.
     *
     * WordPress surfaces the SQLSTATE inside `last_error` as
     * `... for query ... Duplicate entry 'x' for key 'email'`, so the check is a
     * substring test rather than a structured field — `$wpdb` exposes none. The
     * two phrases cover MySQL's wording across the versions WordPress supports.
     */
    private function duplicateFrom(string $error): ?LeadDuplicateException
    {
        if ($error === '') {
            return null;
        }

        $isIntegrityViolation = str_contains($error, self::SQLSTATE_INTEGRITY_VIOLATION)
            || stripos($error, 'Duplicate entry') !== false;

        return $isIntegrityViolation ? new LeadDuplicateException() : null;
    }

    /**
     * Raise {@see LeadDuplicateException} if the error was an integrity
     * violation, and return otherwise so the caller can raise the real error.
     */
    private function throwIfDuplicate(string $error, string $action): void
    {
        $duplicate = $this->duplicateFrom($error);

        if ($duplicate !== null) {
            throw $duplicate;
        }
    }

    /**
     * Now, as MySQL's `datetime` format, in UTC.
     *
     * Not `current_time('mysql', true)`: that is a WordPress function, and these
     * repositories run against an in-memory `$wpdb` with no WordPress loaded.
     *
     * Deliberately not `date('Y-m-d H:i:s')` either, which reads the process
     * timezone and labels the result UTC without converting, storing a different
     * instant on any machine not set to GMT.
     */
    private function now(): string
    {
        return $this->formatDate(new \DateTimeImmutable('now'));
    }

    /**
     * A `DateTimeImmutable` as a MySQL `datetime` string, in UTC.
     *
     * The conversion is the point: `setTimezone()` moves the instant, so a
     * timestamp created in any zone lands in the column as the correct UTC value
     * rather than as its local wall-clock reading.
     */
    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Wrap a table name in backticks, escaping any backtick inside it.
     */
    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * The `$wpdb` handle, resolved on first use.
     */
    private function db(): wpdb
    {
        if ($this->wpdb === null) {
            $this->wpdb = $GLOBALS['wpdb'] ?? null;

            if (!$this->wpdb instanceof wpdb) {
                throw new RuntimeException(
                    'There is no $wpdb instance. A lead repository cannot run outside WordPress, '
                    . 'and it cannot run during a hook that fires before the database is loaded.'
                );
            }
        }

        return $this->wpdb;
    }

    /**
     * A uniform exception for a statement `$wpdb` reported as failed.
     */
    private function queryFailed(string $action, string $error): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Failed to %s: %s',
            $action,
            $error !== '' ? $error : 'the database reported no error message'
        ));
    }
}
