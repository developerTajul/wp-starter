<?php

declare(strict_types=1);

namespace Core\Contact\Domain\DataObjects;

use Core\Contact\Domain\Entities\ContactEntity;

/**
 * A contact together with the records the detail read drags in. The related
 * rows are flattened, so nothing above Infrastructure has to know how the
 * relations are read. The attached companies travel on the entity itself.
 */
final readonly class ContactDetailData
{
    /**
     * @param ContactEntity                    $contact
     * @param array<int, array<string, mixed>> $activities
     */
    public function __construct(
        public ContactEntity $contact,
        public array $activities = [],
    ) {}
}
