<?php
declare(strict_types=1);

/**
 * Probe: does FakeWpdb support the limiter's atomic upsert?
 *
 * Run: php tests/manual/probe-rate-limiter.php
 *
 * Prints PASS/FAIL and the exact SQL the limiter issues, so the fake's parser can
 * be extended against observed output rather than against a guess.
 */

require __DIR__ . '/../bootstrap.php';

use App\Database\AtomicDatabaseRateLimiter;
use Tests\Support\FakeWpdb;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if ($ok) {
        echo "  PASS  {$label}\n";

        return;
    }

    $failures++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$db = new FakeWpdb();

// Create the limiter table through the fake's own DDL path, so the probe uses the
// same schema the migration produces.
$db->query(
    'CREATE TABLE `wp_mini_rate_limits` ('
    . '`id` bigint unsigned NOT NULL AUTO_INCREMENT, '
    . '`rate_key` varchar(64) NOT NULL, '
    . '`attempts` int unsigned NOT NULL DEFAULT 0, '
    . '`expires_at` int unsigned NOT NULL, '
    . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_rate_key` (`rate_key`)'
    . ')'
);

$limiter = new AtomicDatabaseRateLimiter($db);

echo "1. First hit is allowed\n";
try {
    $r = $limiter->hit('lead:1.2.3.4', 3, 60);
    check('first hit allowed', $r->isAllowed());
    check('attempts == 1', $r->attempts() === 1, "got {$r->attempts()}");
    check('remaining == 2', $r->remaining() === 2, "got {$r->remaining()}");
} catch (Throwable $e) {
    check('first hit ran', false, $e::class . ': ' . $e->getMessage());
}

echo "\n2. SQL the limiter issued\n";
foreach ($db->queries as $q) {
    echo '    ' . preg_replace('/\s+/', ' ', $q) . "\n";
}

echo "\n3. Reaching the limit denies\n";
try {
    $limiter->hit('lead:1.2.3.4', 3, 60);   // 2
    $third = $limiter->hit('lead:1.2.3.4', 3, 60); // 3
    check('third allowed', $third->isAllowed(), "attempts={$third->attempts()}");

    $fourth = $limiter->hit('lead:1.2.3.4', 3, 60); // 4 -> denied
    check('fourth denied', $fourth->isDenied(), "attempts={$fourth->attempts()}");
    check('retry-after positive', $fourth->secondsUntilReset() > 0, "got {$fourth->secondsUntilReset()}");
} catch (Throwable $e) {
    check('limit sequence ran', false, $e::class . ': ' . $e->getMessage());
}

echo "\n4. A different key has its own window\n";
try {
    $other = $limiter->hit('lead:5.6.7.8', 3, 60);
    check('other key allowed', $other->isAllowed());
    check('other key attempts == 1', $other->attempts() === 1, "got {$other->attempts()}");
} catch (Throwable $e) {
    check('second key ran', false, $e::class . ': ' . $e->getMessage());
}

echo "\n5. clear() forgets the key\n";
try {
    $limiter->clear('lead:1.2.3.4');
    $after = $limiter->hit('lead:1.2.3.4', 3, 60);
    check('cleared key starts fresh', $after->attempts() === 1, "got {$after->attempts()}");
} catch (Throwable $e) {
    check('clear ran', false, $e::class . ': ' . $e->getMessage());
}

echo "\n";
if ($failures === 0) {
    echo "ALL PROBES PASSED\n";
    exit(0);
}

echo "{$failures} PROBE(S) FAILED\n";
exit(1);
