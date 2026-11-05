<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Http\Resources\LeadResource;

/**
 * The static default is a real hazard, so its boundaries are worth knowing:
 * does a grant survive into a later construction, and does the false path reset
 * it?
 */
$probes = [];
$probe = function (string $label, bool $ok) use (&$probes): void {
    $probes[] = $ok;
    printf("  %s %s\n", $ok ? 'ok  ' : 'FAIL', $label);
};

$row = ['id' => 1, 'notes' => 'secret', 'email' => 'a@b.test', 'name' => 'A', 'submitted_at' => 'x'];

LeadResource::grantNotes(false);
$probe('default is closed', !array_key_exists('notes', LeadResource::make($row)->resolve()));

LeadResource::grantNotes(true);
$probe('grant opens it', array_key_exists('notes', LeadResource::make($row)->resolve()));

// The hazard: a grant made in one request must not survive into the next.
LeadResource::grantNotes(false);
$probe('revoking closes it again', !array_key_exists('notes', LeadResource::make($row)->resolve()));

// Held across constructions.
LeadResource::grantNotes(true);
$a = LeadResource::make($row);
LeadResource::grantNotes(false);
$b = LeadResource::make($row);
$probe('an instance built while granted keeps its own value',
    array_key_exists('notes', $a->resolve()) && !array_key_exists('notes', $b->resolve()));

// Per-instance override still works for the single case.
LeadResource::grantNotes(false);
$single = LeadResource::make($row);
$single->includeNotes = true;
$probe('a per-instance override still wins', array_key_exists('notes', $single->resolve()));

echo "\n";
$failed = count(array_filter($probes, fn ($p) => !$p));
printf("%d probes, %d failed\n", count($probes), $failed);
exit($failed === 0 ? 0 : 1);
