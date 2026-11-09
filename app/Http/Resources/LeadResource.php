<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Framework\Support\JsonResource;

/**
 * Public shape of a lead.
 *
 * Fields are named one at a time, so a column added to the table stays out of
 * responses until it is added here. `notes` is conditional on a capability,
 * expressed inside the mapping rather than by a second resource class, and is
 * never reachable by a client asking for the field.
 */
final class LeadResource extends JsonResource
{
    /**
     * Whether the caller may see internal notes.
     *
     * Static rather than per-instance because a `JsonResourceCollection`
     * wraps items lazily: the resources do not exist when the controller sets
     * the flag, so setting it on each item would set it on nothing and
     * `notes` would be missing for every caller. `grantNotes()` resets it on
     * the false path so a long-lived process cannot carry a grant forward.
     */
    public static bool $notesGrantedByDefault = false;

    public bool $includeNotes;

    /**
     * Set the capability for every resource built in this request.
     */
    public static function grantNotes(bool $granted): void
    {
        self::$notesGrantedByDefault = $granted;
    }

    public function __construct(mixed $resource = null)
    {
        parent::__construct($resource);

        $this->includeNotes = self::$notesGrantedByDefault;
    }

    /**
     * @return array<string, mixed>
     */
    protected function toArray(): array
    {
        return [
            'id'          => $this->id,
            'email'       => $this->email,
            'name'        => $this->whenLoaded('name'),
            'phone'       => $this->whenLoaded('phone'),
            // The inner whenLoaded() is required: PHP evaluates every
            // argument before the call, so when($flag, $this->notes) would
            // read a missing key and throw on a partial row.
            'notes'       => $this->when($this->includeNotes, $this->whenLoaded('notes')),
            'submitted_at' => $this->submitted_at,
        ];
    }
}
