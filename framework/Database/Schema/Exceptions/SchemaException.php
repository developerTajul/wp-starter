<?php
declare(strict_types=1);

namespace Framework\Database\Schema\Exceptions;

use RuntimeException;

/**
 * Raised when a schema operation cannot be carried out.
 *
 * Extends `RuntimeException` so a catch block written against the framework's
 * other database failures ({@see \Framework\Database\Exceptions\MigrationException})
 * keeps working: both are runtime problems, and a migration catches neither
 * deliberately - the migrator wraps whatever a migration throws.
 *
 * The three constructors below are the only ways this is raised, and they exist
 * so that every message contains the two things an operator needs: **which**
 * operation was being attempted, and **what the database said**. A failure
 * reported as "migration failed" names neither, and sends the reader to the
 * database's own log to find out whether the problem was a duplicate table, a
 * bad column type, or a dropped connection.
 */
final class SchemaException extends RuntimeException
{
    /**
     * The database rejected a statement.
     *
     * @param string $operation What the statement was doing, e.g.
     *                          "create the notes table".
     * @param string $reason    The database's own message, or a stand-in when it
     *                          supplied none. Never empty - an empty reason reads
     *                          as "no error", which is the one thing this is not.
     */
    public static function statementFailed(string $operation, string $reason): self
    {
        return new self(sprintf('Could not %s: %s', $operation, $reason));
    }

    /**
     * A table name that cannot be used as an identifier.
     *
     * Rejected at the point the name is given rather than escaped and hoped for.
     * A name containing a backtick is the specific case that matters: MySQL's
     * escape for one is to double it, which is correct in every position except
     * inside dbDelta's line-oriented parser, so a name that reached the adapter
     * would produce a statement that is either a syntax error or - worse - a
     * `CREATE TABLE` whose body has a line injected into it.
     *
     * Nothing in an application generates such a name, which is exactly why
     * refusing it costs nothing and removes the question.
     */
    public static function invalidTableName(string $table): self
    {
        return self::invalidIdentifier($table, 'table');
    }

    /**
     * A column or index name that cannot be used as an identifier.
     *
     * The same rule and the same reason as {@see invalidTableName()}; separate
     * only so the message can name what was actually wrong. A caller reading
     * "not a usable table name" while dropping a *column* has been told about a
     * different object than the one they wrote, which is the kind of message that
     * sends someone looking in the wrong place.
     *
     * @param string $kind "column" or "index".
     */
    public static function invalidIdentifier(string $name, string $kind): self
    {
        return new self(sprintf(
            '[%s] is not a usable %s name. A %s name may only contain letters, digits, '
            . 'underscores and dollar signs, and may not be empty.',
            $name,
            $kind,
            $kind
        ));
    }

    /**
     * A column definition the builder cannot express for the target database.
     *
     * The narrow case this exists for is an auto-increment column being added to
     * a table that already exists: only one such column is allowed per table, so
     * an "add an id column" helper is a request no database will grant. Saying so
     * is better than emitting SQL that fails with a message about keys.
     */
    public static function unsupportedColumn(string $column, string $table, string $reason): self
    {
        return new self(sprintf(
            'Cannot add column [%s] to table [%s]: %s',
            $column,
            $table,
            $reason
        ));
    }
}
