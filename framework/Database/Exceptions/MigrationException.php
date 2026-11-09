<?php
declare(strict_types=1);

namespace Framework\Database\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when a migration cannot be run for a structural reason: the file does
 * not declare the class its name promises, the directory is missing, or a
 * migration's own up()/down() threw.
 *
 * Failure to run is a deliberate hard stop. A batch that continues past a failed
 * migration runs later migrations against a schema that is not what they expect,
 * and - worse - records them as applied, so the tracking table claims a structure
 * the database does not have. Stopping leaves the table honest about what
 * actually ran.
 */
final class MigrationException extends RuntimeException
{
    /**
     * The migration file exists but does not declare the class its name implies.
     *
     * Almost always one of two mistakes: the class was renamed without renaming
     * the file, or the description does not follow the snake_case convention. The
     * message spells out the expected class so the fix is mechanical.
     */
    public static function classNotFound(string $class, string $path): self
    {
        return new self(sprintf(
            'Migration file [%s] does not declare the class [%s]. The class name is derived from the '
            . 'filename: strip the timestamp and convert the rest to StudlyCase.',
            $path,
            $class
        ));
    }

    /**
     * The declared class does not extend {@see \Framework\Database\Migration}.
     *
     * Checked before anything is run: a class that is missing up()/down() would
     * otherwise fail as an opaque "call to undefined method" partway through a
     * batch, after earlier migrations had already been applied.
     */
    public static function notAMigration(string $class, string $path): self
    {
        return new self(sprintf(
            'Class [%s] declared in [%s] must extend %s.',
            $class,
            $path,
            \Framework\Database\Migration::class
        ));
    }

    /**
     * The configured migrations directory is not a directory.
     *
     * Distinguished from "no migrations found" on purpose: an empty directory is
     * a normal state, a wrong path is a configuration bug that would otherwise
     * report success forever.
     */
    public static function directoryMissing(string $path): self
    {
        return new self(sprintf(
            'Migrations directory [%s] does not exist. Create it, or pass the correct path.',
            $path
        ));
    }

    /**
     * A migration's own up()/down() threw.
     *
     * The original throwable is kept as `previous` so the command can print the
     * full chain rather than losing the actual cause behind this wrapper.
     */
    public static function stepFailed(string $direction, string $migration, Throwable $previous): self
    {
        return new self(
            sprintf('Migration [%s] failed to %s: %s', $migration, $direction, $previous->getMessage()),
            0,
            $previous
        );
    }
}
