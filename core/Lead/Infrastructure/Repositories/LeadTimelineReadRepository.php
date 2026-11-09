<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

use Core\Lead\Application\Contracts\LeadTimelineContract;
use Core\Lead\Domain\ReadModels\LeadTimelineProjectionDTO;
use RuntimeException;
use wpdb;

/**
 * `$wpdb`-backed read for a lead's activity timeline.
 *
 * Reads the activities table, which the Activity module owns, because it answers
 * the lead detail page's reporting question rather than a list the activity pages
 * show (P7).
 *
 * Dates are built from the stored strings as UTC. An earlier Eloquent version used
 * `$casts`, which parsed them in the application timezone; UTC is what this module
 * writes everywhere else.
 *
 * The join below is not executed by the suite, since FakeWpdb rejects `JOIN` by
 * design. `countTimelineForLead()` runs on a plain conjunction and is testable.
 * Treat the joined read as unproven until a `tests/manual/` probe exists.
 *
 * @implements LeadTimelineContract
 */
final class LeadTimelineReadRepository implements LeadTimelineContract
{
    /** Table suffix without the prefix. */
    public const TABLE_SUFFIX = 'activities';

    /**
     * Morph alias a lead's activities are stored under.
     *
     * The column holds aliases rather than class names since the
     * `normalize_activities_trackable_type_to_morph_aliases` migration.
     */
    private const LEAD_TRACKABLE_TYPE = 'lead';

    /**
     * Columns the timeline read selects, each qualified with the `a` alias.
     *
     * Qualified because the statement joins: an unqualified `id` is ambiguous to
     * MySQL and is rejected.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'type', 'subject', 'description', 'due_date', 'completed_at', 'created_at', 'user_id',
    ];

    /**
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily —
     *                        see {@see db()}.
     */
    public function __construct(private ?wpdb $wpdb = null)
    {
    }

    /**
     * The fully-qualified activities table name.
     */
    public function getTableName(): string
    {
        return $this->db()->prefix . self::TABLE_SUFFIX;
    }

    /**
     * {@inheritDoc}
     *
     * Ordered on `created_at`, not `due_date`: a timeline reads in the order things
     * happened, and `due_date` is nullable so sorting on it would leave undated
     * activities unstable. `id` tie-breaks rows sharing a `created_at`.
     *
     * The user join is a LEFT JOIN because `activities.user_id` is nullable and is
     * set to NULL when the user is deleted, so an inner join would silently drop
     * activities whose author is gone. The join itself is the weakest part of this
     * port: WordPress owns that table and its display name is `wp_users.display_name`,
     * not the `name` an Eloquent model would have exposed.
     *
     * `$limit` is bound as `%d`, which MySQL accepts for LIMIT. `%d` does not floor
     * a negative value to zero, hence the clamp in {@see clampLimit()}.
     */
    public function getTimelineForLead(int $leadId, int $limit): array
    {
        $db = $this->db();

        $activities = $this->escapeIdentifier($this->getTableName());
        $users = $this->escapeIdentifier($db->prefix . 'users');

        $selected = implode('`, `a`.`', self::COLUMNS);

        $sql = $db->prepare(
            sprintf(
                'SELECT `a`.`%s`, `u`.`display_name` AS `user_name` '
                . 'FROM `%s` AS `a` '
                . 'LEFT JOIN `%s` AS `u` ON `u`.`ID` = `a`.`user_id` '
                . 'WHERE `a`.`trackable_type` = %%s AND `a`.`trackable_id` = %%d '
                . 'ORDER BY `a`.`created_at` DESC, `a`.`id` DESC '
                . 'LIMIT %%d',
                $selected,
                $activities,
                $users
            ),
            self::LEAD_TRACKABLE_TYPE,
            $leadId,
            $this->clampLimit($limit)
        );

        $rows = $db->get_results($sql, ARRAY_A);

        if ($rows === false) {
            throw $this->queryFailed(
                sprintf('read the activity timeline for lead %d', $leadId),
                $db->last_error
            );
        }

        return array_values(array_map(
            fn (array $row): LeadTimelineProjectionDTO => $this->toProjection($row),
            $rows
        ));
    }

    /**
     * {@inheritDoc}
     *
     * A plain equality conjunction, unlike the joined read, so this one runs on
     * the suite's `FakeWpdb` and is genuinely covered.
     */
    public function countTimelineForLead(int $leadId): int
    {
        $db = $this->db();

        $sql = $db->prepare(
            sprintf(
                'SELECT COUNT(*) FROM `%s` WHERE `trackable_type` = %%s AND `trackable_id` = %%d',
                $this->escapeIdentifier($this->getTableName())
            ),
            self::LEAD_TRACKABLE_TYPE,
            $leadId
        );

        $count = $db->get_var($sql);

        if ($count === null) {
            throw $this->queryFailed(
                sprintf('count the activities logged against lead %d', $leadId),
                $db->last_error
            );
        }

        return (int) $count;
    }

    /**
     * Build the read model from one joined row.
     *
     * Timestamps are formatted here rather than in SQL: `DATE_FORMAT` would need
     * its format string interpolated and would put the ISO-8601 shape somewhere a
     * unit test cannot assert. See the class docblock on parsing as UTC.
     *
     * @param array<string, mixed> $row
     */
    private function toProjection(array $row): LeadTimelineProjectionDTO
    {
        return new LeadTimelineProjectionDTO(
            id: (int) $row['id'],
            type: (string) $row['type'],
            subject: (string) $row['subject'],
            description: ($row['description'] ?? null) === null ? null : (string) $row['description'],
            dueDate: $this->toAtom($row['due_date'] ?? null),
            completedAt: $this->toAtom($row['completed_at'] ?? null),
            createdAt: $this->toAtom($row['created_at'] ?? null),
            // `(int) null` is `0`, so the null test is explicit: an activity
            // whose author was deleted must report no author, not user 0.
            userId: ($row['user_id'] ?? null) === null ? null : (int) $row['user_id'],
            // The LEFT JOIN yields NULL for a deleted author; the JOIN's AS alias
            // is absent from the row only if the driver dropped it, so `?? null`
            // covers that rather than reading an undefined key.
            userName: ($row['user_name'] ?? null) === null ? null : (string) $row['user_name'],
        );
    }

    /**
     * The stored timestamp as ISO-8601 in UTC, or null when the column is empty.
     *
     * `date_create_immutable()` returns false on an unparseable value instead of
     * throwing, so one malformed row yields a missing date rather than taking down
     * the page. An empty string is absent, not an instant: some drivers deliver a
     * nullable `DATETIME` that way.
     *
     * @param mixed $value
     *
     * @return string|null ISO-8601 (`DateTimeInterface::ATOM`), UTC.
     */
    private function toAtom(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = date_create_immutable((string) $value, new \DateTimeZone('UTC'));

        if ($parsed === false) {
            return null;
        }

        return $parsed->format(\DateTimeInterface::ATOM);
    }

    /**
     * Clamp a caller's limit to something the statement can use.
     *
     * `LIMIT 0` returns nothing and a negative limit is a MySQL syntax error. The
     * floor of one keeps the call meaningful; the ceiling stops a typo asking for
     * the whole table.
     */
    private function clampLimit(int $limit): int
    {
        return max(1, min($limit, 500));
    }

    /**
     * Escape an identifier's backticks, without adding the quotes. See
     * {@see LeadNoteWriteRepository::escapeIdentifier()}.
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
                'No $wpdb instance is available. LeadTimelineReadRepository needs a WordPress '
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
