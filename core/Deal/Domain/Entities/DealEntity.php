<?php

declare(strict_types=1);

namespace Core\Deal\Domain\Entities;

use Core\Deal\Domain\DealRules;
use Core\Deal\Domain\Exceptions\InvalidDealException;

/**
 * Domain entity representing a deal.
 */
readonly class DealEntity
{
    /**
     * @param int|null    $id
     * @param string      $title
     * @param int|null    $companyId
     * @param int|null    $contactId
     * @param string      $amount
     * @param string      $currency
     * @param int         $pipelineId
     * @param int         $dealStageId
     * @param string|null $expectedCloseDate
     * @param string|null $actualCloseDate
     * @param string|null $createdAt
     * @param string|null $updatedAt
     */
    private function __construct(
        private readonly ?int $id,
        private readonly string $title,
        private readonly ?int $companyId,
        private readonly ?int $contactId,
        private readonly string $amount,
        private readonly string $currency,
        private readonly int $pipelineId,
        private readonly int $dealStageId,
        private readonly ?string $expectedCloseDate,
        private readonly ?string $actualCloseDate,
        private readonly ?string $createdAt = null,
        private readonly ?string $updatedAt = null,
    ) {}

    /**
     * Builds a new, unsaved deal.
     *
     * @param string      $title
     * @param int|null    $companyId
     * @param int|null    $contactId
     * @param string      $amount
     * @param string      $currency
     * @param int         $pipelineId
     * @param int         $dealStageId
     * @param string|null $expectedCloseDate
     * @param string|null $actualCloseDate
     *
     * @return self
     *
     * @throws InvalidDealException When a field would break one of the deal's invariants.
     */
    public static function create(
        string $title,
        int $pipelineId,
        int $dealStageId,
        ?int $companyId = null,
        ?int $contactId = null,
        string $amount = '0.00',
        string $currency = 'BDT',
        ?string $expectedCloseDate = null,
        ?string $actualCloseDate = null,
    ): self {
        self::assertTitleIsUsable($title);
        self::assertAmountIsUsable($amount);
        self::assertCurrencyIsUsable($currency);
        self::assertCompanyIdIsUsable($companyId);
        self::assertContactIdIsUsable($contactId);
        self::assertPipelineIdIsUsable($pipelineId);
        self::assertStageIdIsUsable($dealStageId);

        return new self(
            id: null,
            title: $title,
            companyId: $companyId,
            contactId: $contactId,
            amount: $amount,
            currency: $currency,
            pipelineId: $pipelineId,
            dealStageId: $dealStageId,
            expectedCloseDate: $expectedCloseDate,
            actualCloseDate: $actualCloseDate,
        );
    }

    /**
     * Rebuilds a deal from persisted state. The stored fields are taken as
     * they are, so a row written before a rule existed still loads.
     *
     * @param int         $id
     * @param string      $title
     * @param int|null    $companyId
     * @param int|null    $contactId
     * @param string      $amount
     * @param string      $currency
     * @param int         $pipelineId
     * @param int         $dealStageId
     * @param string|null $expectedCloseDate
     * @param string|null $actualCloseDate
     * @param string|null $createdAt
     * @param string|null $updatedAt
     *
     * @return self
     */
    public static function reconstitute(
        int $id,
        string $title,
        ?int $companyId,
        ?int $contactId,
        string $amount,
        string $currency,
        int $pipelineId,
        int $dealStageId,
        ?string $expectedCloseDate = null,
        ?string $actualCloseDate = null,
        ?string $createdAt = null,
        ?string $updatedAt = null,
    ): self {
        return new self(
            id: $id,
            title: $title,
            companyId: $companyId,
            contactId: $contactId,
            amount: $amount,
            currency: $currency,
            pipelineId: $pipelineId,
            dealStageId: $dealStageId,
            expectedCloseDate: $expectedCloseDate,
            actualCloseDate: $actualCloseDate,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * The stored identifier, or null for a deal that has not been saved yet.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return int|null
     */
    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    /**
     * @return int|null
     */
    public function getContactId(): ?int
    {
        return $this->contactId;
    }

    /**
     * @return string
     */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /**
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @return int
     */
    public function getPipelineId(): int
    {
        return $this->pipelineId;
    }

    /**
     * @return int
     */
    public function getDealStageId(): int
    {
        return $this->dealStageId;
    }

    /**
     * @return string|null
     */
    public function getExpectedCloseDate(): ?string
    {
        return $this->expectedCloseDate;
    }

    /**
     * @return string|null
     */
    public function getActualCloseDate(): ?string
    {
        return $this->actualCloseDate;
    }

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * Moves the deal to another stage and returns the updated deal.
     *
     * A stage that closes the deal stamps the close date, so a deal reported as
     * won or lost carries the day it happened. A stage that does not close it
     * takes the stored close date off again: a deal reopened from Won is back
     * in play, and leaving the date behind would report it as closed while it
     * still sits on the board.
     *
     * The stored date is kept when the deal was already closed on an earlier
     * day, so re-saving a won deal does not move the date it was won on.
     *
     * @param int         $dealStageId The stage the deal moves to.
     * @param string      $stageName   The name of that stage.
     * @param string|null $closeDate   Today, as the caller reads the clock.
     *
     * @return self
     *
     * @throws InvalidDealException When the stage would break an invariant.
     */
    public function moveToStage(int $dealStageId, string $stageName, ?string $closeDate = null): self
    {
        self::assertStageIdIsUsable($dealStageId);

        $updatedActualCloseDate = self::closeDateFor($stageName, $closeDate, $this->actualCloseDate);

        return new self(
            id: $this->id,
            title: $this->title,
            companyId: $this->companyId,
            contactId: $this->contactId,
            amount: $this->amount,
            currency: $this->currency,
            pipelineId: $this->pipelineId,
            dealStageId: $dealStageId,
            expectedCloseDate: $this->expectedCloseDate,
            actualCloseDate: $updatedActualCloseDate,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * Applies an update payload and returns the updated deal. A null field
     * keeps the stored value. The whole payload is validated before anything is
     * applied, so a rejected update leaves the deal untouched.
     *
     * The close date is not a field a caller sets directly: it is decided by the
     * stage the deal ends up in, so this method takes the stage's name and lets
     * moveToStage() own that rule.
     *
     * @param string|null $title
     * @param int|null    $companyId
     * @param bool        $unlinkCompany   Drops the company link; the deal may sit unlinked.
     * @param int|null    $contactId
     * @param bool        $unlinkContact   Drops the contact link; the deal may sit unlinked.
     * @param string|null $amount
     * @param string|null $currency
     * @param int|null    $pipelineId
     * @param int|null    $dealStageId
     * @param string|null $stageName
     * @param string|null $expectedCloseDate
     * @param string|null $closeDate Today, as the caller reads the clock.
     *
     * @return self
     *
     * @throws InvalidDealException When a change would break an invariant.
     */
    public function applyChanges(
        ?string $title = null,
        ?int $companyId = null,
        bool $unlinkCompany = false,
        ?int $contactId = null,
        bool $unlinkContact = false,
        ?string $amount = null,
        ?string $currency = null,
        ?int $pipelineId = null,
        ?int $dealStageId = null,
        ?string $stageName = null,
        ?string $expectedCloseDate = null,
        ?string $closeDate = null,
    ): self {
        $updatedTitle = $title ?? $this->title;
        $updatedCompanyId = $unlinkCompany ? null : ($companyId ?? $this->companyId);
        $updatedContactId = $unlinkContact ? null : ($contactId ?? $this->contactId);
        $updatedAmount = $amount ?? $this->amount;
        $updatedCurrency = $currency ?? $this->currency;
        $updatedPipelineId = $pipelineId ?? $this->pipelineId;
        $updatedStageId = $dealStageId ?? $this->dealStageId;
        $updatedStageName = $stageName ?? ($dealStageId === null ? null : '');

        // A pipeline or a stage that arrived without the other has to be checked
        // against what the deal will actually sit in, so the two are resolved
        // together rather than one at a time.
        self::assertTitleIsUsable($updatedTitle);
        self::assertAmountIsUsable($updatedAmount);
        self::assertCurrencyIsUsable($updatedCurrency);
        self::assertCompanyIdIsUsable($updatedCompanyId);
        self::assertContactIdIsUsable($updatedContactId);
        self::assertPipelineIdIsUsable($updatedPipelineId);
        self::assertStageIdIsUsable($updatedStageId);
        self::assertExpectedCloseDateIsUsable($expectedCloseDate);

        $updatedDeal = new self(
            id: $this->id,
            title: $updatedTitle,
            companyId: $updatedCompanyId,
            contactId: $updatedContactId,
            amount: $updatedAmount,
            currency: $updatedCurrency,
            pipelineId: $updatedPipelineId,
            dealStageId: $updatedStageId,
            expectedCloseDate: $expectedCloseDate ?? $this->expectedCloseDate,
            actualCloseDate: $this->actualCloseDate,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );

        // A stage edit is what decides the close date, so it goes back through
        // the method that owns that rule. A pipeline edit on its own moves the
        // deal to a stage the caller has not named, so it is left alone.
        if ($stageName === null) {
            return $updatedDeal;
        }

        return $updatedDeal->moveToStage(
            dealStageId: $updatedStageId,
            stageName: $updatedStageName,
            closeDate: $closeDate,
        );
    }

    /**
     * Guards the title invariant: a deal without a title cannot be told apart
     * from any other.
     *
     * @param string $title
     *
     * @return void
     *
     * @throws InvalidDealException When the title is blank or too long.
     */
    private static function assertTitleIsUsable(string $title): void
    {
        if (trim($title) === '') {
            throw InvalidDealException::emptyTitle();
        }

        if (mb_strlen($title) > DealRules::MAX_TITLE_LENGTH) {
            throw InvalidDealException::titleTooLong(mb_strlen($title));
        }
    }

    /**
     * Guards the amount invariant: the column holds 15 digits with 2 decimals
     * and cannot store a negative value, so a supplied amount has to be a
     * number inside that range.
     *
     * @param string $amount
     *
     * @return void
     *
     * @throws InvalidDealException When the amount is not a number the column accepts.
     */
    private static function assertAmountIsUsable(string $amount): void
    {
        if (! is_numeric($amount)) {
            throw InvalidDealException::invalidAmount($amount);
        }

        if ((float) $amount < (float) DealRules::MIN_AMOUNT) {
            throw InvalidDealException::invalidAmount($amount);
        }

        if ((float) $amount > (float) DealRules::MAX_AMOUNT) {
            throw InvalidDealException::invalidAmount($amount);
        }
    }

    /**
     * Guards the currency invariant: the column holds a three-letter code.
     *
     * @param string $currency
     *
     * @return void
     *
     * @throws InvalidDealException When the currency is not a three-letter code.
     */
    private static function assertCurrencyIsUsable(string $currency): void
    {
        if (mb_strlen($currency) !== DealRules::CURRENCY_LENGTH) {
            throw InvalidDealException::invalidCurrency($currency);
        }

        if (preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
            throw InvalidDealException::invalidCurrency($currency);
        }
    }

    /**
     * Guards the optional company link: when one is supplied it has to
     * identify a company.
     *
     * @param int|null $companyId
     *
     * @return void
     *
     * @throws InvalidDealException When the supplied ID cannot identify a company.
     */
    private static function assertCompanyIdIsUsable(?int $companyId): void
    {
        if ($companyId !== null && $companyId <= 0) {
            throw InvalidDealException::invalidCompanyId($companyId);
        }
    }

    /**
     * Guards the optional contact link: when one is supplied it has to
     * identify a contact.
     *
     * @param int|null $contactId
     *
     * @return void
     *
     * @throws InvalidDealException When the supplied ID cannot identify a contact.
     */
    private static function assertContactIdIsUsable(?int $contactId): void
    {
        if ($contactId !== null && $contactId <= 0) {
            throw InvalidDealException::invalidContactId($contactId);
        }
    }

    /**
     * Guards the pipeline invariant: a deal belongs to a pipeline, so the
     * column is not nullable.
     *
     * @param int $pipelineId
     *
     * @return void
     *
     * @throws InvalidDealException When the ID cannot identify a pipeline.
     */
    private static function assertPipelineIdIsUsable(int $pipelineId): void
    {
        if ($pipelineId <= 0) {
            throw InvalidDealException::invalidPipelineId($pipelineId);
        }
    }

    /**
     * Guards the stage invariant: a deal sits in a stage, so the column is not
     * nullable.
     *
     * @param int $dealStageId
     *
     * @return void
     *
     * @throws InvalidDealException When the ID cannot identify a stage.
     */
    private static function assertStageIdIsUsable(int $dealStageId): void
    {
        if ($dealStageId <= 0) {
            throw InvalidDealException::invalidStageId($dealStageId);
        }
    }

    /**
     * Guards the optional expected close date: the column is a date, so a
     * supplied one has to be a date.
     *
     * @param string|null $expectedCloseDate
     *
     * @return void
     *
     * @throws InvalidDealException When the value is not a date.
     */
    private static function assertExpectedCloseDateIsUsable(?string $expectedCloseDate): void
    {
        if ($expectedCloseDate === null) {
            return;
        }

        if (self::isDate($expectedCloseDate)) {
            return;
        }

        throw InvalidDealException::invalidCloseDate($expectedCloseDate);
    }

    /**
     * The close date the deal carries after moving to a stage.
     *
     * A stage that closes the deal needs a date to report, so the stored one is
     * kept when there is one and today is stamped when there is not. Any other
     * stage clears it, since a deal sitting on the board is not closed.
     *
     * @param string      $stageName         The name of the stage being moved to.
     * @param string|null $closeDate         Today, as the caller reads the clock.
     * @param string|null $storedCloseDate   The date the deal already carries.
     *
     * @return string|null
     *
     * @throws InvalidDealException When the supplied date is not a date.
     */
    private static function closeDateFor(
        string $stageName,
        ?string $closeDate,
        ?string $storedCloseDate,
    ): ?string {
        if (! self::closesTheDeal($stageName)) {
            return null;
        }

        if ($storedCloseDate !== null) {
            return $storedCloseDate;
        }

        if ($closeDate === null) {
            return null;
        }

        if (! self::isDate($closeDate)) {
            throw InvalidDealException::invalidCloseDate($closeDate);
        }

        return $closeDate;
    }

    /**
     * Whether a stage name is one of the names a closed deal sits in.
     *
     * Compared case-insensitively, because the stages table's unique index does
     * not see case either, so "closed won" and "Closed Won" are one name.
     *
     * @param string $stageName
     *
     * @return bool
     */
    private static function closesTheDeal(string $stageName): bool
    {
        $normalisedStageName = mb_strtolower(trim($stageName));

        return $normalisedStageName === mb_strtolower(DealRules::WON_STAGE_NAME)
            || $normalisedStageName === mb_strtolower(DealRules::LOST_STAGE_NAME);
    }

    /**
     * Whether a value is a date stored as a wall-clock day.
     *
     * @param string $value
     *
     * @return bool
     */
    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat(format: '!Y-m-d', datetime: $value);

        if ($date === false) {
            return false;
        }

        return $date->format('Y-m-d') === $value;
    }
}
