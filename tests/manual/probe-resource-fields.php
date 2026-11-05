<?php
/**
 * Probe: is only() a filter or a reach?
 *
 * The unit tests assert this against fixtures. This proves it against the
 * *mechanism* — that filtering happens after toArray(), so the set of reachable
 * keys is exactly the set toArray() produced, and no name can widen it.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Framework\Support\JsonResource;
use Framework\Support\JsonResourceCollection;

$probes = [];
$probe = function (string $label, bool $ok) use (&$probes): void {
    $probes[] = $ok;
    printf("  %s %s\n", $ok ? 'ok  ' : 'FAIL', $label);
};

/** A resource that maps two public fields and refuses to map a secret. */
$resource = new class (['id' => 1, 'public_a' => 'A', 'public_b' => 'B', 'secret' => 'LEAK']) extends JsonResource {
    protected function toArray(): array
    {
        return [
            'id'       => $this->id,
            'public_a' => $this->public_a,
            'public_b' => $this->public_b,
        ];
    }
};

echo "\nonly() as a filter\n";

$narrowed = (clone $resource)->only(['public_a']);
$out = $narrowed->resolve();
$probe('keeps the requested field', $out['public_a'] === 'A');
$probe('drops the unrequested field', !array_key_exists('public_b', $out));
$probe('always keeps id', array_key_exists('id', $out));

$reaching = (clone $resource)->only(['secret']);
$out = $reaching->resolve();
$probe('cannot reach a field toArray() did not map', !array_key_exists('secret', $out));
$probe('the secret value is nowhere in the payload', !str_contains(json_encode($reaching), 'LEAK'));

$out = (clone $resource)->only(['public_a', 'public_b', 'secret'])->resolve();
$probe('naming every real field plus the secret keeps only the real ones',
    $out === ['id' => 1, 'public_a' => 'A', 'public_b' => 'B']);

echo "\nonly() on a collection\n";

$collection = new JsonResourceCollection([
    ['id' => 1, 'public_a' => 'A1', 'public_b' => 'B1', 'secret' => 'LEAK1'],
    ['id' => 2, 'public_a' => 'A2', 'public_b' => 'B2', 'secret' => 'LEAK2'],
], get_class($resource));

$filtered = $collection->only(['public_a', 'secret'])->resolve();
$probe('every item is narrowed', $filtered['data'][0] === ['id' => 1, 'public_a' => 'A1']
    && $filtered['data'][1] === ['id' => 2, 'public_a' => 'A2']);
$probe('no secret survives the collection anywhere', !str_contains(json_encode($collection), 'LEAK'));

echo "\nthe envelope is an array, not an object\n";

$json = json_encode($collection);
$probe('collection payload is {"data":[...]}', str_starts_with($json, '{"data":['));
$probe('no integer keys were stringified into object keys', !str_contains($json, '"0"'));

$keyed = new JsonResourceCollection([9 => ['id' => 7, 'public_a' => 'A', 'public_b' => 'B'], 3 => ['id' => 8, 'public_a' => 'C', 'public_b' => 'D']], get_class($resource));
$probe('associative input still encodes as a JSON array', str_starts_with(json_encode($keyed), '{"data":['));

echo "\n";

$failed = count(array_filter($probes, fn ($p) => !$p));
printf("%d probes, %d failed\n", count($probes), $failed);

exit($failed === 0 ? 0 : 1);
