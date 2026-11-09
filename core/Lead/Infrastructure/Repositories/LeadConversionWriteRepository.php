<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

use Core\Lead\Domain\Contracts\LeadConversionRepositoryContract;
use Core\Lead\Domain\Entities\LeadConversionLinkEntity;
use DateTimeImmutable;
use RuntimeException;
use wpdb;

/**
 * `$wpdb`-backed writes for the lead conversion link.
 *
 * Not Eloquent: see {@see LeadNoteWriteRepository}. The Eloquent models and
 * `BaseRepository` live in a Laravel `vendor/` this plugin does not have, so the
 * class was unreachable while Composer's PSR-4 map compiled it regardless.
 *
 * `lead_conversions` records that a lead became a deal, since `deals` carries no
 * `lead_id`. This class is one of the few permitted to know that, so "how is a
 * conversion stored" is answerable from one file.
 *
 * @implements LeadConversionRepositoryContract
 */
final class LeadConversionWriteRepository implements LeadConversionRepositoryContract
{
    /** Table suffix without the prefix. See {@see LeadNoteWriteRepository}. */
    public const TABLE_SUFFIX = 'lead_conversions';

    /**
     * Columns the entity is rebuilt from.
     *
     * Explicit rather than `SELECT *`: a new column would otherwise arrive in
     * {@see toLink()} as an unexpected key.
     *
     * @var list<string>
     */
    private const COLUMNS = ['id', 'lead_id', 'contact_id', 'company_id', 'deal_id', 'converted_at'];

    /**
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily; see {@see db()}. Optional so the container can build this before WordPress finishes loading.
     */
    public function __construct(private ?wpdb $wpdb = null)
    {
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
     * The `converted_at` value comes from the entity rather than from this class
     * reading the clock, because the instant a conversion *happened* is domain
     * state: a retried write must record the moment the conversion was decided,
     * not the moment the retry reached the database.
     */
    public function create(LeadConversionLinkEntity $link): LeadConversionLinkEntity
    {
        $db = $this->db();

        $columns = [
            'lead_id' => $link->leadId,
            'contact_id' => $link->contactId,
            'company_id' => $link->companyId,
            'deal_id' => $link->dealId,
            'converted_at' => $this->formatDate($link->convertedAt),
        ];

        $ok = $db->insert($this->getTableName(), $columns, $this->formatsFor($columns));

        if ($ok === false) {
            throw $this->queryFailed('store the lead conversion link', $db->last_error);
        }

        $id = (int) $db->insert_id;

        if ($id <= 0) {
            throw new RuntimeException(
                'The lead conversion link was inserted but no id was reported. A link returned '
                . 'without its id cannot be identified for any later write.'
            );
        }

        $stored = $this->readById($id);

        if ($stored === null) {
            throw new RuntimeException(sprintf(
                'Lead conversion link %d could not be read back after it was inserted.',
                $id
            ));
        }

        return $stored;
    }

    /**
     * {@inheritDoc}
     *
     * `FOR UPDATE` needs a transaction to mean anything. Read outside one, MySQL
     * releases the lock as soon as the statement's implicit transaction ends and
     * the call becomes an ordinary read that merely looks safe — worse than not
     * locking, because the code reads as if it were protected. The caller takes
     * this inside the transaction that is about to write, so two conversions of
     * the same lead are serialised: the second sees what the first committed and
     * reports the conflict instead of attempting a second row.
     */
    public function findByLeadIdForUpdate(int $leadId): ?LeadConversionLinkEntity
    {
        $db = $this->db();

        $sql = $db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `lead_id` = %%d FOR UPDATE',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->getTableName())
            ),
            $leadId
        );

        $row = $db->get_row($sql, ARRAY_A);

        return $row === null ? null : $this->toLink($row);
    }

    /**
     * Read a stored link back by its own id.
     *
     * @return LeadConversionLinkEntity|null
     */
    private function readById(int $id): ?LeadConversionLinkEntity
    {
        $db = $this->db();

        $sql = $db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `id` = %%d',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->getTableName())
            ),
            $id
        );

        $row = $db->get_row($sql, ARRAY_A);

        return $row === null ? null : $this->toLink($row);
    }

    /**
     * Rebuild the entity from a stored row.
     *
     * Every column arrives as a string (the MySQL client protocol has no integer
     * type), so ids are cast unless `strict_types=1` would raise a `TypeError`.
     *
     * `company_id` and `deal_id` get an explicit null test rather than a cast:
     * `(int) null` is `0`, which would link a lead with no company to company `0`.
     *
     * @param array<string, mixed> $row
     *
     * @throws RuntimeException When the row carries no `converted_at`. The column is NOT NULL, so an absent value means the query did not select it.
     */
    private function toLink(array $row): LeadConversionLinkEntity
    {
        $convertedAt = $row['converted_at'] ?? null;

        if ($convertedAt === null || $convertedAt === '') {
            throw new RuntimeException(
                'A stored lead conversion carries no converted_at value. The column is NOT NULL in '
                . 'the schema, so this means the query did not select it.'
            );
        }

        return new LeadConversionLinkEntity(
            id: isset($row['id']) ? (int) $row['id'] : null,
            leadId: (int) $row['lead_id'],
            contactId: (int) $row['contact_id'],
            companyId: ($row['company_id'] ?? null) === null ? null : (int) $row['company_id'],
            dealId: ($row['deal_id'] ?? null) === null ? null : (int) $row['deal_id'],
            convertedAt: new DateTimeImmutable((string) $convertedAt),
        );
    }

    /**
     * Format an instant for the `DATETIME` column, in storage time.
     *
     * UTC explicitly, matching every other timestamp this module writes: the
     * site's timezone is a display concern, and a column mixing local and UTC
     * values is unresolvable afterwards.
     */
    private function formatDate(DateTimeImmutable $date): string
    {
        return $date
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * The `$wpdb` format specifier for each column. See
     * {@see LeadNoteWriteRepository::formatsFor()} for why the null arm is
     * explicit.
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
     * Escape an identifier's backticks, without adding the quotes — the
     * `sprintf` templates above supply those.
     */
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
                'No $wpdb instance is available. LeadConversionWriteRepository needs a WordPress '
                . 'database handle; pass one to the constructor when running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }

    /**
     * A failure naming the operation rather than only the driver's message,
     * which is often empty. See {@see LeadNoteWriteRepository::queryFailed()}.
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
