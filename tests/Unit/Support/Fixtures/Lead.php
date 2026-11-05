<?php
declare(strict_types=1);

namespace Tests\Unit\Support\Fixtures;

/**
 * A plain domain entity — a readonly value object, not an array.
 *
 * The shape a resource normally maps: public properties, a couple of accessors,
 * and a field (`internalRef`) that an API client has no business seeing. That
 * last one is the point of the whole resource layer — it is public on the entity
 * and absent from every response, because absence is a decision someone wrote
 * down rather than a default.
 *
 * ## Why `phone` needs {@see LeadWithPhone}
 *
 * An *optional* column is modelled in PHP as a declared property defaulting to
 * null:
 *
 *     public readonly ?string $phone = null,
 *
 * which is exactly how a real entity represents one — and is precisely why this
 * entity cannot be the fixture for `whenLoaded()`. `property_exists()` answers
 * **true** for that property whether or not anyone supplied a phone, so
 * `whenLoaded('phone')` emits the key holding null. That is not a bug: the
 * framework resolves a declared property, and `null` is what is there. It is
 * also not what a caller means by "the data did not carry this field".
 *
 * "The data does not carry this field" needs a subject on which the property is
 * genuinely absent, which means a second class rather than a second value — PHP
 * has no per-instance absent property. Hence {@see LeadWithPhone} for the
 * present case and a bare anonymous object for the absent one.
 */
final class Lead
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $internalRef,
        public readonly ?string $phone = null,
    ) {
    }

    public function label(): string
    {
        return 'Lead #' . $this->id;
    }

    public function isActive(): bool
    {
        return true;
    }
}
