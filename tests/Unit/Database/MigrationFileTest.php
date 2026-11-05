<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Database\MigrationFile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The filename convention, parsed on its own.
 *
 * Worth testing separately from the migrator because the rules are pure string
 * work that several other behaviours depend on — batch ordering, the class that
 * gets instantiated, what the tracking table stores — and a mistake here surfaces
 * far away as "the class does not exist".
 */
final class MigrationFileTest extends TestCase
{
    /**
     * The canonical form, `Y_m_d_His`.
     *
     * The timestamp segment here is 17 characters, not 14: `2026_11_05_100000`
     * is four digits, three underscore-separated pairs, then six. An earlier
     * version of this parser counted a flat 14 digits and rejected every real
     * filename, so this case is the regression guard for exactly that.
     */
    public function test_it_parses_the_laravel_timestamp_form(): void
    {
        $migration = MigrationFile::fromPath('/tmp/2026_11_05_100000_create_users_table.php');

        $this->assertInstanceOf(MigrationFile::class, $migration);
        $this->assertSame('2026_11_05_100000_create_users_table', $migration->identifier);
        $this->assertSame('CreateUsersTable', $migration->class);
        $this->assertSame('2026_11_05_100000', $migration->timestamp());
    }

    public function test_it_parses_the_compact_timestamp_form(): void
    {
        $migration = MigrationFile::fromPath('/tmp/20261105100000_create_users_table.php');

        $this->assertInstanceOf(MigrationFile::class, $migration);
        $this->assertSame('CreateUsersTable', $migration->class);
        $this->assertSame('20261105100000', $migration->timestamp());
    }

    /**
     * A PHP file that is not a migration is skipped, not reported. The migrator
     * relies on this so a stray file in the directory cannot break `migrate`.
     */
    public function test_it_rejects_a_name_that_is_not_a_migration(): void
    {
        $this->assertNull(MigrationFile::fromPath('/tmp/helpers.php'));
        $this->assertNull(MigrationFile::fromPath('/tmp/2026-11-05-100000_create_users_table.php'));
        $this->assertNull(MigrationFile::fromPath('/tmp/2026_11_05_100000.php'));
        $this->assertNull(MigrationFile::fromPath('/tmp/create_users_table.php'));
        $this->assertNull(MigrationFile::fromPath('/tmp/2026_11_05_100000_CreateUsersTable.php'));
    }

    /**
     * Anything after the timestamp that is not snake_case would produce a class
     * name nobody could predict, so it is refused rather than half-parsed.
     */
    public function test_it_rejects_a_description_that_is_not_snake_case(): void
    {
        $this->assertNull(MigrationFile::fromPath('/tmp/2026_11_05_100000_createUsersTable.php'));
        $this->assertNull(MigrationFile::fromPath('/tmp/2026_11_05_100000_create-users-table.php'));
        $this->assertNull(MigrationFile::fromPath('/tmp/2026_11_05_100000_create__users.php'));
        $this->assertNull(MigrationFile::fromPath('/tmp/2026_11_05_100000__create_users.php'));
    }

    public function test_it_accepts_digits_inside_the_description(): void
    {
        $migration = MigrationFile::fromPath('/tmp/2026_11_05_100000_add_v2_columns.php');

        $this->assertSame('AddV2Columns', $migration?->class);
    }

    public function test_it_converts_a_description_to_studly_case(): void
    {
        $this->assertSame('CreateUsersTable', MigrationFile::studly('create_users_table'));
        $this->assertSame('AddStatus', MigrationFile::studly('add_status'));
        $this->assertSame('Add2faToUsers', MigrationFile::studly('add_2fa_to_users'));
    }

    /**
     * A leading digit is legal in a PHP class name, but an implementation relying
     * on `ucwords()` alone can produce something PHP refuses to declare — so the
     * name is prefixed rather than left to fail at declaration time.
     */
    public function test_studly_always_produces_a_declarable_class_name(): void
    {
        $class = MigrationFile::studly('2fa_setup');

        $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_]*$/', $class);
        $this->assertSame('Migration2faSetup', $class);
    }

    public function test_from_identifier_reports_a_bad_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid migration name');

        MigrationFile::fromIdentifier('not-a-migration', '/tmp/not-a-migration.php');
    }

    public function test_from_identifier_builds_the_same_values_as_from_path(): void
    {
        $fromPath = MigrationFile::fromPath('/tmp/2026_11_05_100000_create_users_table.php');
        $fromIdentifier = MigrationFile::fromIdentifier(
            '2026_11_05_100000_create_users_table',
            '/tmp/2026_11_05_100000_create_users_table.php'
        );

        $this->assertSame($fromPath?->identifier, $fromIdentifier->identifier);
        $this->assertSame($fromPath?->class, $fromIdentifier->class);
        $this->assertSame($fromPath?->timestamp(), $fromIdentifier->timestamp());
    }

    /**
     * Batch ordering compares timestamps as strings, so the two accepted forms
     * must sort chronologically. If they did not, a project mixing them would
     * apply migrations in the wrong order.
     */
    public function test_timestamps_sort_chronologically(): void
    {
        $migrations = [
            MigrationFile::fromPath('/tmp/2026_11_05_120000_later_change.php'),
            MigrationFile::fromPath('/tmp/2026_11_05_100000_earlier_change.php'),
            MigrationFile::fromPath('/tmp/2026_11_06_090000_next_day.php'),
        ];

        usort(
            $migrations,
            static fn (MigrationFile $a, MigrationFile $b): int => strcmp($a->timestamp(), $b->timestamp())
        );

        $this->assertSame(
            [
                '2026_11_05_100000_earlier_change',
                '2026_11_05_120000_later_change',
                '2026_11_06_090000_next_day',
            ],
            array_map(static fn (MigrationFile $m): string => $m->identifier, $migrations)
        );
    }
}
