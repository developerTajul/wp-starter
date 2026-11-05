<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Framework\Support\JsonResource;

/**
 * The public shape of a lead.
 *
 * ## The field list is the contract
 *
 * Every key here is a promise to a client, and the keys that are *not* here are
 * the more important half of it: `internalRef` and `ownerId` are fields the
 * database has and the API deliberately does not publish. Because `toArray()`
 * names fields one at a time, a column added to the table next month is absent
 * from every response until somebody writes a line for it here — which is the
 * whole reason this class exists rather than `json_encode($lead)`.
 *
 * ## Why `notes` is conditional
 *
 * A resource maps one shape, but not every caller is entitled to every field of
 * it. `when()` is how that is expressed *inside* the mapping rather than by
 * having two near-identical resource classes that drift apart.
 *
 * It is worth being precise about what that flag is: it is set by the controller
 * from a capability check, and it is **not** something a client can reach by
 * asking. A `?fields=notes` request against a non-entitled caller gets nothing,
 * because the field was never mapped into the payload for `only()` to keep. See
 * the sparse-fieldset section of the README — `only()` narrows, it does not
 * grant.
 *
 * ## Why the nested whenLoaded()
 *
 *     'notes' => $this->when($this->includeNotes, $this->whenLoaded('notes')),
 *
 * The inner call is not redundant, and leaving it out is a real bug rather than
 * a style choice. PHP evaluates **every** argument to a call before the call
 * happens, so `when($flag, $this->notes)` reads `$this->notes` unconditionally —
 * including on a partially-loaded row that has no `notes` key, where the read
 * throws. The condition was supposed to make that row safe, and evaluating it
 * this way makes it throw instead.
 *
 * `whenLoaded()` defers the read: it returns the marker when the key is absent,
 * so the outer `when()` receives a value it never has to have resolved. This is
 * the general shape for "a field that is both optional and permissioned", and it
 * is worth recognising as such rather than rediscovering the throw.
 */
final class LeadResource extends JsonResource
{
    /**
     * Whether the caller may see internal notes.
     *
     * A public property rather than a constructor argument because a resource is
     * normally built by `make()` or by `collection()`, and neither can pass one.
     * The controller sets it afterwards, at the point where the capability is
     * actually known.
     *
     * ## Why this is static
     *
     * For the *single* case a per-instance flag is enough. For the collection
     * case it is not, and the reason is easy to miss: a `JsonResourceCollection`
     * wraps its items lazily, so the resources it will use do not exist yet when
     * the controller sets the flag. Writing
     *
     *     foreach ($collection->items() as $item) { $item->includeNotes = true; }
     *
     * iterates the *raw rows*, not resources, so the flag is set on nothing and
     * every response comes back with `notes` silently missing — for an entitled
     * caller too. That version passes a test that only asserts the *absent* case,
     * which is how it survives review.
     *
     * A class-level default set once per request is what the collection can
     * actually see at map time. It is honest about what the value is: a property
     * of how this response is being built, not of any one record. It is reset by
     * `grantNotes()` on the false path, so a long-lived process cannot carry an
     * earlier request's grant forward — the failure mode a static invites.
     */
    public static bool $notesGrantedByDefault = false;

    public bool $includeNotes;

    /**
     * Set the capability for every resource this class builds in this request.
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
            'notes'       => $this->when($this->includeNotes, $this->whenLoaded('notes')),
            'submitted_at' => $this->submitted_at,
        ];
    }
}
