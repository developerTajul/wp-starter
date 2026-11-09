<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Entities;

use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Events\LeadConvertedEvent;
use Core\Lead\Domain\Exceptions\InvalidLeadException;
use Core\Lead\Domain\Rules\LeadRules;
use Core\Lead\Domain\Rules\LeadTransitions;
use DateTimeImmutable;

/**
 * A lead and the status rules it enforces on itself.
 *
 * Status moves only through transitionTo(), which delegates the guard to
 * LeadTransitions. Every behaviour method returns a new instance; the original
 * is left untouched.
 */
readonly class LeadEntity
{
    /**
     * @param ?int         $id           Identity. Null until the lead is stored.
     * @param string       $name
     * @param string|null  $email
     * @param string|null  $phone
     * @param string|null  $companyName
     * @param LeadStatus   $status
     * @param string       $source
     * @param string       $createdAt    Stored UTC wall-clock string.
     * @param string       $updatedAt    Stored UTC wall-clock string.
     * @param int|null     $assignedTo   Owning user's id, or null when unassigned.
     * @param list<object> $domainEvents Events raised by this instance.
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $companyName,
        private LeadStatus $status,
        public string $source,
        public string $createdAt,
        public string $updatedAt,
        private ?int $assignedTo = null,
        private array $domainEvents = [],
    ) {}

    /**
     * Creates a lead that has not been stored yet.
     *
     * @param string      $name
     * @param string|null $email
     * @param string|null $phone
     * @param string|null $companyName
     * @param LeadStatus  $status
     * @param string      $source
     *
     * @return self
     *
     * @throws InvalidLeadException When the name is blank or too long.
     */
    public static function create(
        string $name,
        ?string $email,
        ?string $phone,
        ?string $companyName,
        LeadStatus $status,
        string $source,
    ): self {
        return new self(
            id: null,
            name: self::assertNameIsUsable($name),
            email: $email,
            phone: $phone,
            companyName: $companyName,
            status: $status,
            source: $source,
            createdAt: '',
            updatedAt: '',
        );
    }

    /**
     * Returns the lead's current status.
     *
     * @return LeadStatus
     */
    public function getStatus(): LeadStatus
    {
        return $this->status;
    }

    /**
     * Returns the id of the user who owns the lead, or null when unassigned.
     *
     * @return int|null
     */
    public function getAssignedTo(): ?int
    {
        return $this->assignedTo;
    }

    /**
     * Reports whether anyone owns the lead.
     *
     * @return bool
     */
    public function isAssigned(): bool
    {
        return $this->assignedTo !== null;
    }

    /**
     * Hands the lead to a user and returns the new instance.
     *
     * Assigning is unguarded beyond the id being positive: the plan states who
     * may assign (an Admin) but says nothing about which leads may change
     * hands, so inventing a transition rule here would be inventing a business
     * rule. The column is a nullable foreign key and this mirrors it. Whether
     * the target user exists is the port's business, not the entity's - the
     * entity holds no registry of users.
     *
     * @param int $userId The user taking ownership.
     *
     * @return self A new instance; this one is left untouched.
     *
     * @throws InvalidLeadException When the id is not a usable user id.
     */
    public function assignTo(int $userId): self
    {
        if ($userId < 1) {
            throw InvalidLeadException::invalidAssignee($userId);
        }

        return $this->withOwner($userId);
    }

    /**
     * Releases the lead so it is owned by nobody.
     *
     * @return self A new instance; this one is left untouched.
     */
    public function unassign(): self
    {
        return $this->withOwner(null);
    }

    /**
     * Applies an edit to the lead's own fields and returns a new instance.
     *
     * The lead's name, email, phone and company are all nullable columns, so
     * "leave this alone" and "empty this" cannot both be carried by null. Each
     * clearable field carries an explicit flag: a null value with its flag set
     * empties the column, and a null value without it keeps the stored one.
     *
     * Status and the owner are not editable through here. Both move through
     * their own guarded methods - transitionTo() and assignTo() - which is what
     * keeps the transition rules the only way into those columns.
     *
     * @param string      $name             The lead's name; never blank.
     * @param string|null $email
     * @param bool        $clearEmail       Empty the stored email.
     * @param string|null $phone
     * @param bool        $clearPhone       Empty the stored phone.
     * @param string|null $companyName
     * @param bool        $clearCompanyName Empty the stored company.
     * @param string|null $source           Leave the stored source when null.
     *
     * @return self A new instance; this one is left untouched.
     *
     * @throws InvalidLeadException When a supplied field would break an invariant.
     */
    public function applyChanges(
        string $name,
        ?string $email = null,
        bool $clearEmail = false,
        ?string $phone = null,
        bool $clearPhone = false,
        ?string $companyName = null,
        bool $clearCompanyName = false,
        ?string $source = null,
    ): self {
        return new self(
            id: $this->id,
            name: self::assertNameIsUsable($name),
            email: $clearEmail ? null : ($email ?? $this->email),
            phone: $clearPhone ? null : ($phone ?? $this->phone),
            companyName: $clearCompanyName ? null : ($companyName ?? $this->companyName),
            status: $this->status,
            source: $source ?? $this->source,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            assignedTo: $this->assignedTo,
            domainEvents: $this->domainEvents,
        );
    }

    /**
     * Moves the lead to a new status. The only way the status changes.
     *
     * @param LeadStatus $to
     *
     * @return self A new instance; this one is left untouched.
     *
     * @throws InvalidLeadException When LeadTransitions rejects the move.
     */
    public function transitionTo(LeadStatus $to): self
    {
        LeadTransitions::assertCan($this->status, $to);

        return $this->withStatus($to);
    }

    /**
     * Marks the lead as contacted. Allowed from new and lost.
     *
     * @return self
     *
     * @throws InvalidLeadException When the transition is not allowed.
     */
    public function markAsContacted(): self
    {
        return $this->transitionTo(LeadStatus::CONTACTED);
    }

    /**
     * Marks the lead as qualified. Only allowed from contacted.
     *
     * @return self
     *
     * @throws InvalidLeadException When the transition is not allowed.
     */
    public function qualify(): self
    {
        return $this->transitionTo(LeadStatus::QUALIFIED);
    }

    /**
     * Marks the lead as converted. Only allowed from qualified.
     *
     * @return self
     *
     * @throws InvalidLeadException When the transition is not allowed.
     */
    public function convert(): self
    {
        return $this->transitionTo(LeadStatus::CONVERTED);
    }

    /**
     * Returns the lead converted, carrying the event that announces it.
     *
     * The rule is checked before the copy is built, so an ineligible lead never
     * becomes a converted one. Conversion answers with a new instance because
     * the caller may still roll back, and a rolled-back convert must not leave
     * a mutated lead behind.
     *
     * Converting a lead means turning it into a customer, and a customer
     * carries an email: the contacts table makes the column NOT NULL, so a
     * lead without one cannot produce the contact the conversion creates. The
     * rule is enforced here, before the status moves, so the lead is refused
     * outright rather than converting into a record set that cannot be built.
     *
     * @param DateTimeImmutable $occurredAt The moment the conversion happens.
     *
     * @return self
     *
     * @throws InvalidLeadException When the transition is not allowed or the lead carries no email.
     */
    public function converted(DateTimeImmutable $occurredAt): self
    {
        LeadTransitions::assertCan($this->status, LeadStatus::CONVERTED);

        $this->assertEmailIsUsable();

        // The event names the lead, so a row that was never stored cannot raise it.
        if ($this->id === null) {
            throw InvalidLeadException::conversionNeedsStoredLead();
        }

        return new self(
            id: $this->id,
            name: $this->name,
            email: $this->email,
            phone: $this->phone,
            companyName: $this->companyName,
            status: LeadStatus::CONVERTED,
            source: $this->source,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            assignedTo: $this->assignedTo,
            domainEvents: [
                ...$this->domainEvents,
                new LeadConvertedEvent(
                    leadId: $this->id,
                    name: $this->name,
                    email: $this->email,
                    phone: $this->phone,
                    companyName: $this->companyName,
                    source: $this->source,
                    occurredAt: $occurredAt,
                ),
            ],
        );
    }

    /**
     * Marks the lead as lost. Not allowed from converted.
     *
     * @return self
     *
     * @throws InvalidLeadException When the transition is not allowed.
     */
    public function markAsLost(): self
    {
        return $this->transitionTo(LeadStatus::LOST);
    }

    /**
     * Returns the events this instance raised.
     *
     * @return list<object>
     */
    public function releaseEvents(): array
    {
        return $this->domainEvents;
    }

    /**
     * Builds a copy carrying a new status and the current owner.
     *
     * @param LeadStatus $status
     *
     * @return self
     */
    private function withStatus(LeadStatus $status): self
    {
        return $this->copy(status: $status, assignedTo: $this->assignedTo);
    }

    /**
     * Builds a copy carrying a new owner and the current status.
     *
     * @param int|null $assignedTo
     *
     * @return self
     */
    private function withOwner(?int $assignedTo): self
    {
        return $this->copy(status: $this->status, assignedTo: $assignedTo);
    }

    /**
     * Builds a copy of the lead, forwarding any events already held.
     *
     * @param LeadStatus $status
     * @param int|null   $assignedTo
     *
     * @return self
     */
    private function copy(LeadStatus $status, ?int $assignedTo): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            email: $this->email,
            phone: $this->phone,
            companyName: $this->companyName,
            status: $status,
            source: $this->source,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            assignedTo: $assignedTo,
            domainEvents: $this->domainEvents,
        );
    }

    /**
     * Guards the email the conversion depends on. A stored blank is treated the
     * same as a stored null, because both leave the contact's NOT NULL column
     * with nothing to hold.
     *
     * @return void
     *
     * @throws InvalidLeadException When the lead carries no usable email.
     */
    private function assertEmailIsUsable(): void
    {
        if ($this->email === null || trim($this->email) === '') {
            throw InvalidLeadException::conversionNeedsEmail();
        }
    }

    /**
     * Guards the name invariant: an unnamed lead cannot be told apart from any
     * other. Mirrors the column, which is NOT NULL, and its 255-character
     * width.
     *
     * @param string $name
     *
     * @return string The trimmed name, ready to store.
     *
     * @throws InvalidLeadException When the name is blank or too long.
     */
    private static function assertNameIsUsable(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw InvalidLeadException::emptyName();
        }

        if (mb_strlen($trimmed) > LeadRules::MAX_NAME_LENGTH) {
            throw InvalidLeadException::nameTooLong(mb_strlen($trimmed));
        }

        return $trimmed;
    }
}