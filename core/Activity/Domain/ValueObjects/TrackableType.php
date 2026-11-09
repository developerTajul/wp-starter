<?php

declare(strict_types=1);

namespace Core\Activity\Domain\ValueObjects;

use Core\Activity\Domain\Exceptions\InvalidActivityException;

/**
 * The type of record an activity is attached to, as a morph alias.
 */
final readonly class TrackableType
{
    /**
     * The record types an activity may be attached to, as morph aliases.
     * Without this, any type from request input would be accepted as a
     * morph target.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TYPES = [
        'lead',
        'deal',
        'contact',
        'company',
    ];

    private function __construct(private string $value) {}

    /**
     * Builds the value object from morph aliases supplied to a write.
     *
     * @param string $value
     *
     * @return self
     *
     * @throws InvalidActivityException When the value is blank or not an allowed record type.
     */
    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidActivityException::emptyTrackableType();
        }

        if (! in_array($value, self::ALLOWED_TYPES, true)) {
            throw InvalidActivityException::unsupportedTrackableType($value);
        }

        return new self($value);
    }

    /**
     * Rebuilds the value object from a stored morph alias. Reads do not apply
     * the write-time allowlist, so an alias left over from an earlier or
     * renamed record type cannot break loading a row that already exists.
     *
     * @param string $value
     *
     * @return self
     *
     * @throws InvalidActivityException When the stored value is blank.
     */
    public static function fromPersisted(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidActivityException::emptyTrackableType();
        }

        return new self($value);
    }

    /**
     * The record types that may be attached to an activity.
     *
     * @return array<int, string>
     */
    public static function allowedTypes(): array
    {
        return self::ALLOWED_TYPES;
    }

    /**
     * Returns the morph alias as stored.
     *
     * @return string
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Whether both value objects hold the same morph alias.
     *
     * @param self $other
     *
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
