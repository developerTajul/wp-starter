<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\LeadResource;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Responses\JsonResponse;
use Framework\Support\JsonResourceCollection;

/**
 * Read‑only REST endpoint for leads.
 *
 * Demonstrates the delivery layer: a JsonResource defines shape, a
 * JsonResourceCollection wraps many, and JsonResponse sends it. No adapter is
 * needed because a resource is JsonSerializable.
 */
final class LeadController
{
    /**
     * Capability that allows a caller to see lead notes.
     */
    public const NOTES_CAPABILITY = 'read_lead_notes';

    /**
     * Return all leads as a JSON collection.
     */
    public function index(): ResponseInterface
    {
        // Grant notes visibility based on the capability.
        LeadResource::grantNotes($this->maySeeNotes());
        return new JsonResponse(LeadResource::collection($this->leads()));
    }

    /**
     * Return a single lead or 404.
     */
    public function show(int $id): ResponseInterface
    {
        $lead = $this->find($id);
        if ($lead === null) {
            return new JsonResponse([
                'error'   => 'not_found',
                'message' => sprintf('No lead with id %d.', $id)
            ], ['status_code' => 404]);
        }
        $resource = LeadResource::make($lead);
        $resource->includeNotes = $this->maySeeNotes();
        return new JsonResponse($resource);
    }

    /**
     * Sample data rows.
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
                // No `phone` key – a partial row.
                'notes'        => 'Referred by Ada.',
                'submitted_at' => '2026-11-02T14:05:00Z',
            ],
        ];
    }

    /**
     * Find a lead by id.
     *
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
     * Whether the current user may see notes.
     *
     * Returns false when WordPress functions are unavailable (e.g., unit tests).
     */
    private function maySeeNotes(): bool
    {
        return function_exists('current_user_can')
            ? (bool) current_user_can(self::NOTES_CAPABILITY)
            : false;
    }
}
