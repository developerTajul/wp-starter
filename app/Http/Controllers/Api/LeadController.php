<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\LeadResource;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Responses\JsonResponse;
use Framework\Support\JsonResourceCollection;

/**
 * A read-only REST endpoint over a lead source.
 *
 * ## What this demonstrates
 *
 * The three pieces of the headless path meeting: a `JsonResource` deciding the
 * shape, a `JsonResourceCollection` wrapping many, and a `JsonResponse` sending
 * it. There is no adapter between them — a resource is `JsonSerializable`, so it
 * goes straight into the response the same way an array would.
 *
 * ## Why the source is a method rather than a query
 *
 * This controller is a demonstration of the *delivery* layer, and the project
 * has no leads table yet. Returning rows from a private method keeps that honest
 * — the shape is real, the data is not — and swapping in `$wpdb` later changes
 * one method rather than the shape of the class.
 *
 * ## Why the capability is checked here rather than in the resource
 *
 * `current_user_can()` is a WordPress function, and the resource is a plain
 * mapping object that a test can exercise without the CMS loaded. Deciding what
 * the caller may see is the controller's job; expressing it in the payload is
 * the resource's.
 *
 * ## The two capabilities, and why they are not the same one
 *
 * The route is guarded by {@see RequireLeadRead}, which asks for `list_users` —
 * the narrowest core capability covering "may see other people's contact
 * details". That is what admits a caller here at all.
 *
 * `notes`, however, is a *different* population. It is the internal commentary
 * on a lead — "asked about enterprise pricing", "referred by Ada" — and it is
 * strictly more sensitive than the email and phone number beside it. An account
 * created to read leads through an integration has a real reason to hold
 * `list_users` and no reason at all to read what the sales team wrote about the
 * customer.
 *
 * So notes are gated on their own capability. Which one is a *site policy*
 * decision rather than a framework one, and WordPress core defines nothing that
 * means "may read internal lead notes" — so the name is the plugin's to invent
 * and the project's to grant.
 *
 * **Nothing grants it yet.** This plugin ships no role-management, so until a
 * project maps `read_lead_notes` onto a role, nobody holds it and the `notes`
 * field is absent for every caller. That is the deliberate direction to fail in:
 * absent until granted, rather than visible until revoked. The grant is one
 * `add_cap()` in the project that wants it — and because the capability is named
 * in exactly one place, that project and this check cannot disagree about the
 * spelling.
 *
 * ## The failure this replaced
 *
 * This check used to read `edit_others_posts` while the middleware read
 * `list_users`, which meant the two answers described different populations. A
 * role holding `list_users` reached the controller, failed the second check, and
 * received a lead with its `notes` key silently **absent** — byte-identical to a
 * lead that genuinely has no notes. No error, no refusal, nothing to debug: the
 * caller cannot tell "you may not see this" from "there is nothing to see", and
 * neither can the person they report it to.
 *
 * Two checks that must agree are one check written twice, so the capability now
 * has exactly one home — {@see self::NOTES_CAPABILITY} — and the guard class
 * names its own.
 */
final class LeadController
{
    /**
     * The capability that admits a caller to a lead's internal notes.
     *
     * A site policy, and the only capability in this plugin that WordPress core
     * does not also define — see the class docblock. Published as a constant so
     * that a role registration, a test, and the check below cannot drift onto
     * three different spellings of it.
     */
    public const NOTES_CAPABILITY = 'read_lead_notes';

    /**
     * Every lead the caller may see.
     */
    public function index(): ResponseInterface
    {
        // The capability is set for the whole request *before* the collection is
        // built. It cannot be applied per item afterwards: a collection wraps its
        // items lazily, so `$collection->items()` yields the raw rows and a
        // `foreach` over it sets the flag on nothing. See LeadResource for why
        // the default lives on the class rather than the instance.
        LeadResource::grantNotes($this->maySeeNotes());

        return new JsonResponse(LeadResource::collection($this->leads()));
    }

    /**
     * One lead.
     *
     * Returns 404 rather than an empty object when the row is missing, because a
     * client that receives `{}` has to guess what happened, and an empty resource
     * and an absent one look identical.
     */
    public function show(int $id): ResponseInterface
    {
        $lead = $this->find($id);

        if ($lead === null) {
            return new JsonResponse(
                ['error' => 'not_found', 'message' => sprintf('No lead with id %d.', $id)],
                ['status_code' => 404]
            );
        }

        $resource = LeadResource::make($lead);
        $resource->includeNotes = $this->maySeeNotes();

        return new JsonResponse($resource);
    }

    /**
     * The rows.
     *
     * @return list<array<string, mixed>>
     */
    private function leads(): array
    {
        return [
            [
                'id'           => 1,
                'email'        => 'ada@example.test',
                'name'         => 'Ada Lovelace',
                'phone'        => '555-0100',
                'notes'        => 'Asked about enterprise pricing.',
                'submitted_at' => '2026-11-01T09:30:00Z',
            ],
            [
                'id'           => 2,
                'email'        => 'grace@example.test',
                'name'         => 'Grace Hopper',
                // No `phone` key at all — a partial row, so the response shows
                // whenLoaded() omitting a field the source genuinely lacks.
                'notes'        => 'Referred by Ada.',
                'submitted_at' => '2026-11-02T14:05:00Z',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        foreach ($this->leads() as $lead) {
            if ((int) $lead['id'] === $id) {
                return $lead;
            }
        }

        return null;
    }

    /**
     * Whether the caller is entitled to the notes field.
     *
     * Answered from the same capability the field's own policy names, and not
     * from a second one that happens to look close — see the class docblock for
     * what that cost.
     *
     * `function_exists` because this controller is exercised by unit tests that
     * load no WordPress. Absent the function, the answer is no: a capability
     * check that cannot run has not passed, and defaulting to visible would make
     * the missing-CMS case the one that leaks.
     */
    private function maySeeNotes(): bool
    {
        return function_exists('current_user_can')
            ? (bool) current_user_can(self::NOTES_CAPABILITY)
            : false;
    }
}
