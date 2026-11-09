<?php
declare(strict_types=1);

namespace Framework\Database\Query\Exceptions;

/**
 * A write was rejected by a unique index.
 *
 * Why this is a distinct type
 *
 * A duplicate key is the one database failure a use case routinely has an answer
 * for: "that email is taken" is a validation message, not a 500. Every other
 * statement failure - a dropped connection, a renamed column - is an
 * infrastructure fault the caller can only report.
 *
 * Telling the two apart by message text is what this class exists to make
 * unnecessary. MySQL's wording is version-dependent, `$wpdb` passes it through
 * untranslated, and a host is free to run a server whose locale spells it
 * differently - so a `str_contains($message, 'Duplicate entry')` test is a rule
 * that silently stops firing on someone else's machine. The detection is made on
 * the driver's **error number** instead, which is part of MySQL's protocol and
 * does not vary; see {@see \Framework\Database\Query\WpFluentQueryBuilder}.
 *
 * Where the mapping to a domain exception happens
 *
 * Not here. This is a *framework* exception, and the framework knows nothing
 * about the application's domain, see F1.2 of the framework rules: a hard import
 * of a `Core\` class would break the moment `Core\` is rebranded.
 *
 * A repository adapter catches this and rethrows the module's own conflict
 * exception, which is the only layer that knows what a duplicate *means* for
 * that table:
 *
 *     try {
 *         $this->newQuery()->insertGetId($row);
 *     } catch (UniqueConstraintException $exception) {
 *         throw new CompanyDuplicateException($exception->getMessage());
 *     }
 *
 * Extends {@see QueryException}, so a caller that only cares that a query failed
 * needs no second `catch` arm.
 */
final class UniqueConstraintException extends QueryException
{
    /**
     * MySQL's error number for a duplicate value in a unique index.
     *
     * Named rather than written as a bare `1062` at the detection site so the
     * number is stated once, with its meaning beside it.
     */
    public const MYSQL_DUPLICATE_ENTRY = 1062;

    /**
     * @param string $table      The bare table name the write targeted, with no prefix.
     * @param string $constraint The index or key MySQL named, when it reported one,
     *                           and an empty string when it did not. The value is
     *                           taken from the driver's message only to make the
     *                           failure readable - nothing branches on it.
     *                           Passed separately rather than parsed back out of
     *                           the message by a caller, which would put the
     *                           message-matching this class avoids back at the
     *                           call site.
     * @param string $driverMessage The database's own wording, kept verbatim so
     *                           the operator sees what the server actually said.
     */
    public static function duplicateEntry(string $table, string $constraint, string $driverMessage): self
    {
        return new self(
            sprintf(
                'A unique constraint rejected the write to [%s]%s: %s',
                $table,
                $constraint !== '' ? sprintf(' on [%s]', $constraint) : '',
                $driverMessage !== '' ? $driverMessage : 'the database reported no error message'
            ),
            0,
            null,
            'write to ' . $table,
            $constraint
        );
    }

    /**
     * @param string $constraint The index MySQL named, or an empty string.
     */
    private function __construct(
        string $message,
        int $code,
        ?\Throwable $previous,
        string $operation,
        public readonly string $constraint = '',
    ) {
        parent::__construct($message, $code, $previous, $operation);
    }
}
