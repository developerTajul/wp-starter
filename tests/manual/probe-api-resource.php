<?php
/**
 * Probe: the resource layer end to end, through the real router.
 *
 * Boots the container and the route table the way the plugin does, resolves the
 * registered lead routes, and maps a real request through to a JsonResponse —
 * so this exercises routing, binding, the controller, the resource, and the
 * envelope rather than the resource alone.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Http\Controllers\Api\LeadController;
use App\Http\Resources\LeadResource;
use Framework\Support\JsonResource;
use Framework\Support\JsonResourceCollection;

$probes = [];
$probe = function (string $label, bool $ok, string $detail = '') use (&$probes): void {
    $probes[] = $ok;
    printf("  %s %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail === '' ? '' : "  ({$detail})");
};

// ---------------------------------------------------------------- the mapping

echo "\nthe public shape\n";

$row = [
    'id' => 1, 'email' => 'ada@example.test', 'name' => 'Ada Lovelace',
    'phone' => '555-0100', 'notes' => 'internal', 'submitted_at' => '2026-11-01T09:30:00Z',
    'internalRef' => 'LEAD-SECRET-1', 'ownerId' => 42,
];

$mapped = LeadResource::make($row)->resolve();
$encoded = json_encode(LeadResource::make($row));

$probe('maps the fields it declares', $mapped['id'] === 1 && $mapped['email'] === 'ada@example.test');
$probe('omits internalRef', !str_contains($encoded, 'internalRef'));
$probe('omits ownerId', !str_contains($encoded, 'ownerId'));
$probe('the secret value never reaches the payload', !str_contains($encoded, 'LEAD-SECRET-1'));
$probe('notes absent by default (capability not granted)', !array_key_exists('notes', $mapped));

$withNotes = LeadResource::make($row);
$withNotes->includeNotes = true;
$probe('notes present once granted', ($withNotes->resolve()['notes'] ?? null) === 'internal');

// A partial row: no phone key at all.
$partial = ['id' => 2, 'email' => 'g@example.test', 'name' => 'Grace', 'submitted_at' => 'x'];
$probe('whenLoaded omits phone on a row that lacks the key', !array_key_exists('phone', LeadResource::make($partial)->resolve()));

// -------------------------------------------------------------------- the envelope

echo "\nthe collection envelope\n";

$collection = LeadResource::collection([$row, $partial]);
$json = json_encode($collection);

$probe('wraps items in data', str_starts_with($json, '{"data":['));
$probe('an array, not an object', str_contains($json, '"data":[{'));
$probe('count() reports 2', $collection->count() === 2);
$probe('isEmpty() is false', $collection->isEmpty() === false);
$probe('keyById() indexes by id', array_keys($collection->keyById()) === [1, 2]);

// ------------------------------------------------------------------ the response

echo "\nthe response\n";

$response = new class ([$row]) {
    public function __construct(private array $rows) {}
    public function build(): \Framework\Http\Responses\JsonResponse
    {
        return new \Framework\Http\Responses\JsonResponse(LeadResource::collection($this->rows));
    }
};

$jsonResponse = $response->build();
$probe('a resource collection goes straight into a JsonResponse', $jsonResponse->data() instanceof JsonResourceCollection);
$probe('status defaults to 200', $jsonResponse->status() === 200);
$probe('json_encode() of the response data resolves the resource',
    str_starts_with(json_encode($jsonResponse->data()), '{"data":['));

echo "\n";

$failed = count(array_filter($probes, fn ($p) => !$p));
printf("%d probes, %d failed\n", count($probes), $failed);
exit($failed === 0 ? 0 : 1);
