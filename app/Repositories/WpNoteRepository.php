<?php
declare(strict_types=1);

namespace App\Repositories;

use Core\Note\Domain\NoteEntity;
use Core\Note\Domain\NoteRepositoryContract;
use Core\Shared\PaginatedResult;
use RuntimeException;
use wpdb;

/**
 * Note storage on `$wpdb`.
 *
 * ## What this class is, and what it is not
 *
 * This is the one place in the note module that knows a table name, a column
 * name, and that the storage engine is MySQL. Every method above it — the
 * service, the entities, the request classes — is phrased in notes and ids.
 * Swapping storage means writing a second implementation of
 * {@see NoteRepositoryContract} and changing one binding in
 * {@see \App\Providers\RepositoryServiceProvider}; nothing else moves.
 *
 * It is the mirror image of {@see \Framework\Database\Migrator}: that class owns
 * the schema, this one reads and writes rows in it.
 *
 * ## Why it throws where the contract returns null
 *
 * The contract's "absent" answers — `find()` returning null, `delete()`
 * returning false — are ordinary outcomes and are returned as values. A
 * *database failure* is not: `$wpdb` reports one by returning false or null from
 * a call that should have succeeded, and the two are only distinguishable by
 * checking {@see wpdb::$last_error}. Silently reading a failed SELECT as "no
 * rows" would turn a broken query into a 404 that says the note does not exist,
 * which is both wrong and unactionable.
 *
 * So a failed statement raises a {@see RuntimeException} carrying `$wpdb`'s own
 * message. That is a programming or infrastructure fault rather than something a
 * user caused, which is exactly the case
 * {@see \Core\Note\Application\NoteService} documents as "throw, don't return".
 *
 * ## The table name
 *
 * `{$wpdb->prefix}notes`, so two installs sharing a database cannot collide and
 * a multisite subsite gets its own table. The name is built from the prefix and a
 * constant and is never interpolated from user input — but it is still escaped
 * for backticks through {@see escapeIdentifier()}, for the same reason the
 * migrator does it: no caller should have to reason about whether a particular
 * identifier is safe.
 */
final class WpNoteRepository implements NoteRepositoryContract
{
    /**
     * Table suffix, appended to `$wpdb->prefix`.
     *
     * Matches the table `migrate` creates for the notes module.
     */
    public const TABLE_SUFFIX = 'notes';

    /**
     * `$wpdb`'s "return an associative array" flag.
     *
     * Named here rather than passed as a bare `ARRAY_A` so the intent reads at
     * the call site, and so the constant is referenced from one place if the
     * queries ever move.
     */
    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * @param wpdb|null $wpdb WordPress database handle. Defaults to the `$wpdb`
     *                         global, resolved lazily so the repository can be
     *                         constructed during `register()` — before
     *                         WordPress has necessarily finished loading — and
     *                         injected in tests without touching a global.
     */
    public function __construct(private ?wpdb $wpdb = null)
    {
    }

    /**
     * The fully-qualified table name.
     *
     * Public because a migration, a seeder, or an admin screen may need the same
     * name, and re-deriving it from the prefix and the constant at each of those
     * sites is how the two drift apart.
     */
    public function getTableName(): string
    {
        return $this->db()->prefix . self::TABLE_SUFFIX;
    }

    // ------------------------------------------------------------------ reading

    public function find(int $id): ?NoteEntity
    {
        return $this->findWhere('`id` = %d', [$id]);
    }

    public function findOwnedBy(int $id, int $userId): ?NoteEntity
    {
        // Both predicates are in the query, not applied afterwards. See the
        // contract: reading the row and then checking ownership works right up
        // until the check is moved, and a row the caller may not see should
        // never leave the database at all.
        return $this->findWhere('`id` = %d AND `user_id` = %d', [$id, $userId]);
    }

    /**
     * @return PaginatedResult<NoteEntity>
     */
    public function paginateForUser(int $userId, int $perPage, int $page): PaginatedResult
    {
        $db = $this->db();
        $table = $this->escapeIdentifier($this->getTableName());

        // Clamped here as well as in PaginatedResult, because the LIMIT and
        // OFFSET are computed below from these numbers *before* the value object
        // exists — and a negative OFFSET is rejected by MySQL and silently read
        // as zero by SQLite, so the two backends would disagree about the same
        // request. The service clamps too; a repository reached directly by a
        // console command would not have been through it.
        $perPage = max(1, min($perPage, PaginatedResult::MAX_PER_PAGE));
        $page = max(1, $page);

        // The total across every page, not the size of this slice. A repository
        // that passed `count($rows)` here is how a paginated API reports "1 of 1"
        // for every page — see PaginatedResult::fromSlice().
        $total = $db->get_var($db->prepare(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `user_id` = %%d', $table),
            $userId
        ));

        if ($total === null && $db->last_error !== '') {
            throw $this->queryFailed('count this user\'s notes', $db->last_error);
        }

        $offset = ($page - 1) * $perPage;

        // `%d` for both, so neither can arrive as a string and be quoted.
        // Newest first, which is the order the contract promises and the order a
        // client's "page 1" is expected to show. `id DESC` is the tiebreak rather
        // than `created_at` alone, because two notes saved in the same second
        // would otherwise come back in an order the database is free to change
        // between calls — and a row appearing on two pages while another is
        // skipped is the visible symptom.
        $rows = $db->get_results($db->prepare(
            sprintf(
                'SELECT * FROM `%s` WHERE `user_id` = %%d ORDER BY `created_at` DESC, `id` DESC LIMIT %%d OFFSET %%d',
                $table
            ),
            $userId,
            $perPage,
            $offset
        ), self::OUTPUT_ASSOC);

        if (!is_array($rows)) {
            throw $this->queryFailed('read this user\'s notes', $db->last_error);
        }

        return PaginatedResult::fromSlice(
            array_map(fn (array $row): NoteEntity => $this->hydrate($row), $rows),
            (int) $total,
            $perPage,
            $page
        );
    }

    // ------------------------------------------------------------------ writing

    public function save(NoteEntity $note): NoteEntity
    {
        // The insert/update decision is taken from the entity, which is the one
        // thing that knows whether it has an id. Asking here rather than letting
        // the caller choose is what stops a caller INSERTing a note that already
        // exists and producing a silent duplicate.
        return $note->exists()
            ? $this->update($note)
            : $this->insert($note);
    }

    public function delete(int $id): bool
    {
        $db = $this->db();

        $affected = $db->query($db->prepare(
            sprintf('DELETE FROM `%s` WHERE `id` = %%d', $this->escapeIdentifier($this->getTableName())),
            $id
        ));

        if ($affected === false) {
            throw $this->queryFailed(sprintf('delete note %d', $id), $db->last_error);
        }

        // `query()` returns the number of affected rows for a DELETE, so 0 means
        // "there was no row" — which the contract reports as false rather than as
        // an error. See the contract for why a delete of something already gone
        // is not a failure.
        return $affected > 0;
    }

    // ------------------------------------------------------------------ internals

    /**
     * One row matching a WHERE clause over the notes table, or null.
     *
     * @param string       $where  A fragment containing `%d` placeholders; never
     *                             interpolated from user input.
     * @param list<int>    $params Values for the placeholders, in order.
     */
    private function findWhere(string $where, array $params): ?NoteEntity
    {
        $db = $this->db();

        $sql = sprintf(
            'SELECT * FROM `%s` WHERE %s LIMIT 1',
            $this->escapeIdentifier($this->getTableName()),
            $where
        );

        // get_row() with ARRAY_A returns null when there is no row *and* on a
        // query error, so last_error is what separates "no such note" from "the
        // statement was broken". Reading a failure as emptiness would report a
        // missing note where the real answer is that the database is unreachable.
        $row = $db->get_row($db->prepare($sql, ...$params), self::OUTPUT_ASSOC);

        if ($row === null && $db->last_error !== '') {
            throw $this->queryFailed('read a note', $db->last_error);
        }

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Store a note that has never been saved, returning it with the assigned id.
     *
     * WordPress's own `$wpdb->insert()` is used rather than a hand-written INSERT
     * so the column/value escaping is WordPress's rather than this class's; it
     * returns false on failure and never throws, which is checked below.
     */
    private function insert(NoteEntity $note): NoteEntity
    {
        $db = $this->db();

        $ok = $db->insert(
            $this->getTableName(),
            [
                'title' => $note->title,
                'content' => $note->content,
                'user_id' => $note->userId,
                'created_at' => $this->formatDate($note->createdAt),
                'updated_at' => $this->formatDate($note->updatedAt),
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );

        if ($ok === false) {
            throw $this->queryFailed('store a new note', $db->last_error);
        }

        $id = (int) $db->insert_id;

        // The contract is explicit that an implementation which cannot determine
        // the assigned id must fail loudly: an entity returned without an id is
        // one where `exists()` is false, so every later save would insert another
        // copy rather than updating this row.
        if ($id <= 0) {
            throw new RuntimeException(
                'The note was inserted but no id was reported. A note returned without its id '
                . 'would be re-inserted on its next save, silently duplicating the row.'
            );
        }

        // `withId()` returns a Result and only ever fails for an id that is
        // non-positive or already set — neither of which is reachable here, and
        // both of which are bugs rather than user outcomes, so the failure is
        // raised rather than returned. The entity comes back carrying the id
        // storage assigned, which is what the caller keeps.
        $stamped = $note->withId($id);

        if ($stamped->isFailure()) {
            throw new RuntimeException(sprintf(
                'The note was inserted as id %d, but could not be stamped with it: %s',
                $id,
                $stamped->message()
            ));
        }

        /** @var NoteEntity $entity */
        $entity = $stamped->value();

        return $entity;
    }

    /**
     * Write an existing note back over its row.
     *
     * The entity reaches the caller only from {@see find()} or {@see insert()},
     * so it always carries an id here; the guard below is what makes that
     * assumption checkable rather than merely stated.
     */
    private function update(NoteEntity $note): NoteEntity
    {
        $db = $this->db();
        $id = $note->id;

        if ($id === null) {
            throw new RuntimeException(
                'update() was reached with a note that has no id. A note without an id cannot name '
                . 'the row to update; save() routes those to insert().'
            );
        }

        $ok = $db->update(
            $this->getTableName(),
            [
                'title' => $note->title,
                'content' => $note->content,
                'user_id' => $note->userId,
                'updated_at' => $this->formatDate($note->updatedAt),
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );

        if ($ok === false) {
            throw $this->queryFailed(sprintf('update note %d', $id), $db->last_error);
        }

        return $note;
    }

    /**
     * Map a raw row onto a domain entity.
     *
     * {@see NoteEntity::reconstitute()} rather than `create()`: the row came out
     * of storage, so its values are trusted — they were validated on the way in.
     * Re-validating here would reject historical rows that predate a rule added
     * since, and would be checking the database's work rather than a caller's
     * input. The distinction is the whole reason the entity has two factories.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NoteEntity
    {
        return NoteEntity::reconstitute(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['content'],
            (int) $row['user_id'],
            $this->parseDate($row['created_at'] ?? null),
            isset($row['updated_at'])
                ? $this->parseDate($row['updated_at'])
                : null
        );
    }

    /**
     * A `DateTimeImmutable` from a MySQL `datetime` column value.
     *
     * MySQL hands back a string with no timezone, and WordPress stores UTC
     * (`current_time('mysql', true)`). The zone is therefore stated explicitly
     * rather than taken from the process default: `new DateTimeImmutable($string)`
     * would silently interpret the value in `date.timezone`, so the same row
     * would become a different instant depending on where PHP was running.
     */
    private function parseDate(mixed $value): \DateTimeImmutable
    {
        $string = is_string($value) ? trim($value) : '';

        if ($string === '') {
            // A row the database wrote but whose timestamp cannot be read is a
            // schema problem, not an absence of a note. Refusing beats inventing
            // "now", which would make an old row look freshly created.
            throw new RuntimeException(
                'A stored note has no usable timestamp. Every note carries a created_at, so a '
                . 'blank one means the column is missing or was written as an empty string.'
            );
        }

        try {
            return new \DateTimeImmutable($string, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new RuntimeException(
                sprintf('A stored note carries an unparseable timestamp [%s].', $string),
                0,
                $e
            );
        }
    }

    /**
     * A `DateTimeImmutable` as MySQL's `datetime` format, in UTC.
     *
     * The counterpart to {@see parseDate()}: the value is converted to UTC before
     * formatting, so an entity carrying a non-UTC instant is stored as the same
     * moment rather than as its local wall-clock reading.
     */
    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * A statement failed, with the database's own explanation.
     *
     * `$wpdb` reports failures by return value, and the reason only exists in
     * `last_error`. Raising here rather than returning a sentinel is what keeps
     * "the query was broken" from being indistinguishable from "there was no
     * row" — the distinction {@see NoteService} relies on when it decides between
     * a 404 and a 500.
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
     * Lazy for the same reason {@see Migrator::db()} is: a provider's `register()`
     * runs before WordPress has necessarily finished setting `$wpdb` up, and a
     * constructor that reached for the global eagerly would fail there.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new RuntimeException(
                'No $wpdb instance is available. WpNoteRepository needs a WordPress database handle; '
                . 'pass one to the constructor when running outside a loaded WordPress.'
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
