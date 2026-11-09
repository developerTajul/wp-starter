<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Rules;

use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Exceptions\InvalidLeadException;

/**
 * Holds the lead module's allowed status transitions.
 */
final class LeadTransitions
{
    /**
     * The statuses reachable from each status, keyed by the status value.
     *
     * A status is never reachable from itself, so there is no self-transition
     * entry here.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED = [
        LeadStatus::NEW->value => [LeadStatus::CONTACTED->value, LeadStatus::LOST->value],
        LeadStatus::CONTACTED->value => [LeadStatus::QUALIFIED->value, LeadStatus::LOST->value],
        LeadStatus::QUALIFIED->value => [LeadStatus::CONVERTED->value, LeadStatus::LOST->value],
        LeadStatus::CONVERTED->value => [],
        LeadStatus::LOST->value => [LeadStatus::CONTACTED->value],
    ];

    /**
     * Tells whether a lead may move from one status to another.
     *
     * @param LeadStatus $from
     * @param LeadStatus $to
     *
     * @return bool
     */
    public static function can(LeadStatus $from, LeadStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value], true);
    }

    /**
     * The statuses a lead may move to next, in the order they are offered.
     *
     * Derived from ALLOWED, so a status the rules forbid here can never be
     * presented as a choice.
     *
     * @param LeadStatus $from
     *
     * @return list<LeadStatus>
     */
    public static function reachableFrom(LeadStatus $from): array
    {
        return array_values(array_filter(
            LeadStatus::cases(),
            fn(LeadStatus $status) => self::can($from, $status),
        ));
    }

    /**
     * Guards a status change, rejecting one the rules do not allow.
     *
     * @param LeadStatus $from
     * @param LeadStatus $to
     *
     * @return void
     *
     * @throws InvalidLeadException When the transition is not allowed.
     */
    public static function assertCan(LeadStatus $from, LeadStatus $to): void
    {
        if ($from === $to) {
            throw InvalidLeadException::sameStatus($from);
        }

        if (self::can($from, $to)) {
            return;
        }

        throw InvalidLeadException::transitionNotAllowed($from, $to);
    }
}