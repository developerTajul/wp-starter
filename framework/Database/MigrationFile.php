<?php
declare(strict_types=1);

namespace Framework\Database;

use InvalidArgumentException;

/**
 * One migration file, parsed into the three things the migrator needs: the
 * identifier it is tracked under, the class to instantiate, and where it lives.
 *
 * Parsing is separated from discovery so the naming rules can be tested without
 * a filesystem, and so a filename that cannot be parsed is rejected at the point
 * where the reason is still obvious — rather than surfacing later as a class that
 * "does not exist", which names the symptom and not the cause.
 *
 * ## Naming convention
 *
 *     <timestamp>_<snake_case_description>.php
 *     2026_11_05_120000_create_leads_table.php
 *
 * The description is converted to StudlyCase for the class name, the way Laravel
 * does it: `2026_11_05_120000_create_leads_table` becomes `CreateLeadsTable`. A
 * migration file is therefore *named differently* from the class it contains, and
 * unlike a PSR-4 class the file cannot be autoloaded by its class name — the
 * migrator `require`s the file and then checks the class exists.
 *
 * The identifier is the filename without its extension, and is what the tracking
 * table stores. It is the filename rather than the class name on purpose: the
 * class name is derived, so it changes if the conversion rules ever change,
 * whereas the filename is what a developer sees in the directory.
 *
 * A file that does not follow the convention is skipped by
 * {@see Migrator::discover()} rather than aborting discovery, because a stray
 * `.php` file in the directory is a much likelier explanation than a broken
 * migration engine.
 */
final class MigrationFile
{
    /**
     * The full filename without its `.php` extension, e.g.
     * "2026_11_05_100000_create_users_table".
     */
    private function __construct(
        public readonly string $identifier,
        public readonly string $class,
        public readonly string $path,
        public readonly string $timestamp
    ) {
    }

    /**
     * The exact shape a migration filename must have: a timestamp, then a
     * snake_case description.
     *
     * Two timestamp spellings are accepted, because both are in the wild:
     *
     *   `Y_m_d_His`  → `2026_11_05_100000_create_users_table` (Laravel's form)
     *   `YmdHis`     → `20261105100000_create_users_table`
     *
     * The first is 17 characters (`2026_11_05_100000`), not 14 — a timestamp
     * written as `2026_11_05_100000` has *six* underscore-free digits for the
     * time, and counting the whole run as 14 digits silently rejects every
     * filename that uses it. Both forms sort identically as strings, which is
     * all the ordering here relies on.
     *
     * Anchored at both ends so a partly-matching name — a file with a suffix, or
     * a description that is not snake_case — is rejected rather than half-parsed
     * into a class name that would not exist.
     */
    private const PATTERN = '/^(?<timestamp>(?:\d{4}_\d{2}_\d{2}_\d{6})|\d{14})_(?<description>[a-z0-9]+(?:_[a-z0-9]+)*)$/';

    /**
     * Parse a filename into a migration, or return null when it does not follow
     * the convention.
     */
    public static function fromPath(string $path): ?self
    {
        $identifier = basename($path, '.php');

        if (preg_match(self::PATTERN, $identifier, $matches) !== 1) {
            return null;
        }

        return new self(
            $identifier,
            self::studly($matches['description']),
            $path,
            $matches['timestamp']
        );
    }

    /**
     * Build the migration value object for a class the caller already knows.
     *
     * Used by the generator's tests and by {@see Migrator} when it needs to
     * describe a file it has already parsed, so the StudlyCase rules live in
     * exactly one place.
     *
     * @throws InvalidArgumentException When the identifier is not a valid one.
     */
    public static function fromIdentifier(string $identifier, string $path): self
    {
        $migration = self::fromPath($identifier . '.php');

        if ($migration === null) {
            throw new InvalidArgumentException(sprintf(
                '[%s] is not a valid migration name. Expected <timestamp>_<snake_case_description>, '
                . 'e.g. 2026_11_05_100000_create_users_table.',
                $identifier
            ));
        }

        return new self($migration->identifier, $migration->class, $path, $migration->timestamp);
    }

    /**
     * Convert a snake_case description to StudlyCase.
     *
     * `create_leads_table` becomes `CreateLeadsTable`. Digits are kept as written
     * and capitalised like any other word boundary, and a leading digit — which a
     * filename may perfectly well have — is prefixed so the result is always a
     * legal class name rather than one PHP would refuse to declare.
     */
    public static function studly(string $description): string
    {
        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $description)));

        if ($studly === '' || preg_match('/^[0-9]/', $studly) === 1) {
            return 'Migration' . $studly;
        }

        return $studly;
    }

    /**
     * The batch-ordering key.
     *
     * Migrations are ordered by this, never by the directory listing, which comes
     * back in whatever order the filesystem chooses. A batch must run oldest
     * first and roll back newest first, so the ordering has to come from the
     * filename rather than from `scandir()`.
     *
     * ## Why the underscores come out
     *
     * The two accepted spellings do **not** compare as strings. `_` is 0x5F and
     * digits are 0x30–0x39, so `2026_11_05_100000` sorts after `20261231000000`
     * — every underscored timestamp beats every compact one, whatever the dates
     * say. Sorting a mixed directory by the raw filename therefore runs
     * migrations in spelling order rather than date order, and a migration that
     * alters a table can run before the one that creates it.
     *
     * Stripping the separators yields the same 14 digits either way, which does
     * compare correctly. Padding is unnecessary: both spellings carry all four
     * year digits and zero-padded month, day, hour, minute and second, so the
     * normalised strings are always the same length.
     */
    public function timestamp(): string
    {
        return str_replace('_', '', $this->timestamp);
    }
}
