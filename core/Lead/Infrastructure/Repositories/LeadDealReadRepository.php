<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

use Core\Lead\Application\Contracts\LeadDealContract;
use Core\Lead\Domain\ReadModels\LeadDealProjectionDTO;
use RuntimeException;
use wpdb;

/**
 * `$wpdb`-backed read of the deals a lead produced. Reports across the Deal
 * module's tables, so it declares its own read port (P7).
 *
 * The join is hand-written SQL: `$wpdb` offers no builder, only `prepare()` for
 * values and `get_results()` for the statement.
 *
 * Untested by the suite. `tests/Support/FakeWpdb.php` rejects a `JOIN` by
 * design rather than guess at a statement it cannot evaluate, so this method is
 * unproven until probed against a real database.
 *
 * @implements LeadDealContract
 */
final class LeadDealReadRepository implements LeadDealContract
{
    /** The conversions table, owned by this module. */
    public const TABLE_SUFFIX = 'lead_conversions';

    /** The deals table, owned by the Deal module. */
    public const DEALS_TABLE_SUFFIX = 'deals';

    /** The stages table, owned by the Deal module. */
    public const DEAL_STAGES_TABLE_SUFFIX = 'deal_stages';

    /**
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily; see {@see db()}.
     */
    public function __construct(private ?wpdb $wpdb = null)
    {
    }

    /**
     * The fully-qualified conversions table name.
     */
    public function getTableName(): string
    {
        return $this->db()->prefix . self::TABLE_SUFFIX;
    }

    /**
     * {@inheritDoc}
     *
     * Inner join through `lead_conversions`, the table that actually records a
     * conversion: `deals` carries no `lead_id`. A conversion with no matching
     * deal names nothing to print, so the database drops it.
     *
     * The stage is a LEFT JOIN because `deals.deal_stage_id` is nullable, and a
     * deal with no stage must still appear with its amount.
     *
     * Ordered by `converted_at`, not `deals.created_at`: the panel reports when
     * the lead became this deal. `id` tie-breaks conversions in the same second.
     */
    public function getDealsForLead(int $leadId): array
    {
        $db = $this->db();

        $conversions = $this->escapeIdentifier($this->getTableName());
        $deals = $this->escapeIdentifier($db->prefix . self::DEALS_TABLE_SUFFIX);
        $stages = $this->escapeIdentifier($db->prefix . self::DEAL_STAGES_TABLE_SUFFIX);

        $sql = $db->prepare(
            sprintf(
                'SELECT `d`.`id` AS `deal_id`, `d`.`title` AS `title`, `d`.`amount` AS `amount`, '
                . '`d`.`currency` AS `currency`, COALESCE(`s`.`name`, \'\') AS `stage` '
                . 'FROM `%s` AS `c` '
                . 'INNER JOIN `%s` AS `d` ON `d`.`id` = `c`.`deal_id` '
                . 'LEFT JOIN `%s` AS `s` ON `s`.`id` = `d`.`deal_stage_id` '
                . 'WHERE `c`.`lead_id` = %%d '
                . 'ORDER BY `c`.`converted_at` DESC, `c`.`id` DESC',
                $conversions,
                $deals,
                $stages
            ),
            $leadId
        );

        $rows = $db->get_results($sql, ARRAY_A);

        // `false` is a failed statement, `[]` is a lead with no deals. Both are
        // falsy, so a truthiness test would report a broken join as "converted
        // into nothing".
        if ($rows === false) {
            throw $this->queryFailed(
                sprintf('read the deals for lead %d', $leadId),
                $db->last_error
            );
        }

        return array_values(array_map(
            static fn (array $row): LeadDealProjectionDTO => new LeadDealProjectionDTO(
                id: (int) $row['deal_id'],
                title: (string) $row['title'],
                amount: (string) $row['amount'],
                currency: (string) $row['currency'],
                // Kept alongside the COALESCE to cover a driver that ignores it
                // and hands back a real null.
                stage: ($row['stage'] ?? null) === null ? '' : (string) $row['stage'],
            ),
            $rows
        ));
    }

    /** Escape an identifier's backticks without adding the quotes. See {@see LeadNoteWriteRepository::escapeIdentifier()}. */
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
                'No $wpdb instance is available. LeadDealReadRepository needs a WordPress database '
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
