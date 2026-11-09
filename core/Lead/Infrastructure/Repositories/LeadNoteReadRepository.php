<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

use Core\Lead\Application\Contracts\LeadNoteQueryContract;
use Core\Lead\Domain\ReadModels\LeadNoteProjectionDTO;
use Core\Lead\Infrastructure\Mappers\LeadNoteEntityMapper;
use RuntimeException;
use wpdb;

/**
 * `$wpdb`-backed reads for a lead's quick notes. Holds no write path.
 *
 * Not Eloquent, for the reason given on {@see LeadNoteWriteRepository}.
 *
 * Maps straight to {@see LeadNoteProjectionDTO}, but still injects
 * `LeadNoteEntityMapper`: the projection mapping sits beside the entity mapping
 * there, and splitting the two would let column names drift between the write
 * path and this read.
 *
 * @implements LeadNoteQueryContract
 */
final class LeadNoteReadRepository implements LeadNoteQueryContract
{
    /**
     * The bare table suffix, no prefix. Same name and value as
     * {@see LeadNoteWriteRepository::TABLE_SUFFIX}, so a table rename fails in
     * both files rather than silently in one.
     */
    public const TABLE_SUFFIX = 'lead_notes';

    /**
     * Columns the projection is built from.
     *
     * Named rather than `SELECT *`: {@see LeadNoteEntityMapper::toProjection()}
     * indexes the array it is handed, so a missing column is an undefined-index
     * read surfacing as a PHP warning and a `0`, not a failed statement.
     *
     * @var list<string>
     */
    private const COLUMNS = ['id', 'lead_id', 'body', 'is_pinned', 'created_at', 'updated_at'];

    /**
     * @param LeadNoteEntityMapper $mapper
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily; see {@see db()}.
     */
    public function __construct(
        private readonly LeadNoteEntityMapper $mapper,
        private ?wpdb $wpdb = null,
    ) {
    }

    /**
     * The fully-qualified table name.
     */
    public function getTableName(): string
    {
        return $this->db()->prefix . self::TABLE_SUFFIX;
    }

    /**
     * {@inheritDoc}
     *
     * `id DESC` tie-breaks notes written in the same second. Without it MySQL
     * may return those two in either order and differently on the next read, so
     * a note appears to move between two refreshes of an unchanged screen.
     *
     * The direction words are literal: `prepare()` cannot bind them (`%s` would
     * quote them into the literal `'DESC'`), and nothing reaches this string from
     * outside the file.
     */
    public function getNotesForLead(int $leadId): array
    {
        $db = $this->db();

        $sql = $db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `lead_id` = %%d '
                . 'ORDER BY `is_pinned` DESC, `created_at` DESC, `id` DESC',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->getTableName())
            ),
            $leadId
        );

        $rows = $db->get_results($sql, ARRAY_A);

        // `$wpdb` reports a failed statement as `false` and an empty result as
        // `[]`; both are falsy, so a truthiness test would turn a broken query
        // into "this lead has no notes". Hence `=== false`.
        if ($rows === false) {
            throw $this->queryFailed(
                sprintf('read the notes for lead %d', $leadId),
                $db->last_error
            );
        }

        return array_values(array_map(
            fn (array $row): LeadNoteProjectionDTO => $this->mapper->toProjection($row),
            $rows
        ));
    }

    /** Escape an identifier's backticks without adding the quotes; the `sprintf` template above supplies those. See {@see LeadNoteWriteRepository::escapeIdentifier()}. */
    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * The WordPress database handle, resolved on first use. See
     * {@see LeadNoteWriteRepository::db()}.
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
                'No $wpdb instance is available. LeadNoteReadRepository needs a WordPress database '
                . 'handle; pass one to the constructor when running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }

    /** A failure naming the operation, since `$wpdb->last_error` is often empty. See {@see LeadNoteWriteRepository::queryFailed()}. */
    private function queryFailed(string $action, string $error): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Failed to %s. The database reported: %s',
            $action,
            $error === '' ? '(no error message)' : $error
        ));
    }
}
