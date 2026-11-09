<?php
declare(strict_types=1);

namespace Framework\Database\Query\Exceptions;

use RuntimeException;

/**
 * A query could not be built or could not be run.
 *
 * Why one exception for two jobs
 *
 * Building a statement and running one fail for different reasons - a `SELECT`
 * with no table named is a caller's mistake, and a statement the database
 * rejected is an infrastructure fault - but they are the same *kind* of event
 * from the caller's point of view: the query did not produce rows, and there is
 * nothing to branch on. A caller able to do something different for the two is a
 * caller who wants the message rather than the type, and the message already
 * says which happened.
 *
 * Splitting them would mean a repository with two `catch` arms that do the same
 * thing, which is the shape this codebase avoids everywhere it can - see
 * {@see \Framework\Database\Exceptions\MigrationException}, which likewise
 * covers a missing directory and a failed statement.
 *
 * Why it extends `RuntimeException`
 *
 * Every anticipated failure in this framework is thrown as a `RuntimeException`
 * so console commands can catch it and report it the WP-CLI way - a message,
 * not a stack trace. See {@see \Framework\Console\GeneratorCommand::handle()},
 * which catches exactly that type, and {@see \Framework\Database\Migrator},
 * which wraps a failed statement the same way.
 *
 * A caller that wants to distinguish a bad build from a failed run inspects
 * {@see $operation}, which is the phrase the message was written around.
 *
 * Why this is not `final`
 *
 * {@see UniqueConstraintException} extends it, so that a caller which only cares
 * that a query failed keeps one `catch` arm while a caller that has to tell a
 * duplicate key from any other failure can name the narrower type. That is the
 * one exception to the "implementation classes are `final`" rule in this
 * codebase, and it is deliberate: `final` here would force the duplicate-key
 * case into the same type as every other failure, and the caller would be back
 * to matching on the message text - which is exactly what F9 forbids.
 *
 * For the same reason the constructor is `protected` rather than private.
 */
class QueryException extends RuntimeException
{
    /**
     * A statement was rejected by the database.
     *
     * @param string $operation What the query was doing, phrased so a message
     *                          can use it - "read the leads table", "insert a
     *                          lead". The distinction matters because a
     *                          `$wpdb` failure surfaces as a return value and
     *                          the reason exists only in `last_error`, so
     *                          neither half alone is actionable.
     * @param string $error     The database's own explanation, which may be
     *                          empty when the driver reported none.
     */
    public static function statementFailed(string $operation, string $error): self
    {
        return new self(
            sprintf(
                'Could not %s: %s',
                $operation,
                $error !== '' ? $error : 'the database reported no error message'
            ),
            0,
            null,
            $operation
        );
    }

    /**
     * A statement could not be assembled.
     *
     * Raised for the cases the builder can catch before any SQL exists - most
     * often a `SELECT` that never named a table, and an `insertGetId()`/`update()`
     * given no columns. These are programming errors rather than someone's bad
     * input, which is why they are raised here rather than returned.
     *
     * Why they are caught at all
     *
     * The alternative is to emit the statement and let the database reject it.
     * That works, and it reports the failure one layer further away from the
     * cause: `SELECT * FROM `` LIMIT 1` comes back as a syntax error naming a
     * position, and the caller who forgot `table()` has to work backwards to it.
     * Catching it here costs one comparison and names the missing call.
     */
    public static function couldNotBuild(string $reason): self
    {
        return new self($reason);
    }

    protected function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly string $operation = '',
    ) {
        parent::__construct($message, $code, $previous);
    }
}
