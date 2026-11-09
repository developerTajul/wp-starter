<?php

declare(strict_types=1);

namespace Core\Activity\Domain\Exceptions;

use Core\Activity\Domain\ActivityRules;
use RuntimeException;

/**
 * Thrown when an activity would be left in an invalid state.
 */
final class InvalidActivityException extends RuntimeException
{
    /**
     * Reports a missing or blank subject.
     *
     * @return self
     */
    public static function emptySubject(): self
    {
        return new self('An activity must carry a subject.');
    }

    /**
     * Reports a trackable record that was never identified.
     *
     * @param int $trackableId
     *
     * @return self
     */
    public static function invalidTrackableId(int $trackableId): self
    {
        return new self(sprintf('Trackable ID must be a positive integer, %d given.', $trackableId));
    }

    /**
     * Reports a missing trackable type.
     *
     * @return self
     */
    public static function emptyTrackableType(): self
    {
        return new self('An activity must reference a trackable type.');
    }

    /**
     * Reports a trackable type that is not an allowed record type.
     *
     * @param string $trackableType
     *
     * @return self
     */
    public static function unsupportedTrackableType(string $trackableType): self
    {
        return new self(sprintf(
            'Unsupported trackable type [%s].',
            $trackableType,
        ));
    }

    /**
     * Reports a completion of an activity that is already completed.
     *
     * @return self
     */
    public static function alreadyCompleted(): self
    {
        return new self('The activity is already completed.');
    }

    /**
     * Reports a page size outside the supported range.
     *
     * @param int $perPage
     *
     * @return self
     */
    public static function invalidPerPage(int $perPage): self
    {
        return new self(sprintf(
            'Page size must be between %d and %d, %d given.',
            ActivityRules::MIN_PER_PAGE,
            ActivityRules::MAX_PER_PAGE,
            $perPage,
        ));
    }

    /**
     * Reports a trackable filter carrying only one half of the pair.
     *
     * @return self
     */
    public static function incompleteTrackableFilter(): self
    {
        return new self('A trackable filter needs both a type and an ID.');
    }

    /**
     * Reports a payload that both completes and reopens an activity.
     *
     * @return self
     */
    public static function conflictingCompletionAndReopen(): self
    {
        return new self('An activity cannot be completed and reopened by the same update.');
    }

    /**
     * Reports a reopen of an activity that is not completed.
     *
     * @return self
     */
    public static function notCompleted(): self
    {
        return new self('The activity is not completed, so it cannot be reopened.');
    }

    /**
     * Reports a payload that both replaces and clears the due date.
     *
     * @return self
     */
    public static function conflictingDueDateAndClear(): self
    {
        return new self('An activity cannot have its due date replaced and cleared by the same update.');
    }

    /**
     * Reports a completion timestamp that lies in the future.
     *
     * @param \DateTimeImmutable $completedAt
     * @param \DateTimeImmutable $now
     *
     * @return self
     */
    public static function completionInTheFuture(\DateTimeImmutable $completedAt, \DateTimeImmutable $now): self
    {
        return new self(sprintf(
            'An activity cannot be completed in the future: %s given, current time is %s.',
            $completedAt->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
        ));
    }
}
