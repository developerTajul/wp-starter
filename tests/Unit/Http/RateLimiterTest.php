<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Database\AtomicDatabaseRateLimiter;
use Core\Shared\RateLimitResult;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;

final class RateLimiterTest extends TestCase
{
    private FakeWpdb $db;
    private AtomicDatabaseRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();
        // Create the required table – the same schema the migration uses.
        $this->db->query(
            'CREATE TABLE `wp_mini_rate_limits` ('
            . '`id` bigint unsigned NOT NULL AUTO_INCREMENT, '
            . '`rate_key` varchar(64) NOT NULL, '
            . '`attempts` int unsigned NOT NULL DEFAULT 0, '
            . '`expires_at` int unsigned NOT NULL, '
            . 'PRIMARY KEY (`id`), UNIQUE KEY `uniq_rate_key` (`rate_key`)'
            . ')'
        );
        $this->limiter = new AtomicDatabaseRateLimiter($this->db);
    }

    public function test_basic_allow_and_deny(): void
    {
        // First three hits should be allowed, the fourth denied.
        $r1 = $this->limiter->hit('test:key', 3, 60);
        $this->assertTrue($r1->isAllowed());
        $this->assertSame(1, $r1->attempts());
        $this->assertSame(2, $r1->remaining());

        $r2 = $this->limiter->hit('test:key', 3, 60);
        $r3 = $this->limiter->hit('test:key', 3, 60);
        $this->assertTrue($r3->isAllowed());
        $this->assertSame(3, $r3->attempts());
        $this->assertSame(0, $r3->remaining());

        $r4 = $this->limiter->hit('test:key', 3, 60);
        $this->assertTrue($r4->isDenied());
        $this->assertSame(4, $r4->attempts());
        $this->assertGreaterThan(0, $r4->secondsUntilReset());
    }

    public function test_key_is_isolated(): void
    {
        $a = $this->limiter->hit('a', 2, 60);
        $b = $this->limiter->hit('b', 2, 60);
        $this->assertTrue($a->isAllowed());
        $this->assertTrue($b->isAllowed());
        $this->assertSame(1, $a->attempts());
        $this->assertSame(1, $b->attempts());
    }

    public function test_clear_resets_counter(): void
    {
        $this->limiter->hit('clear:key', 2, 60);
        $this->limiter->clear('clear:key');
        $r = $this->limiter->hit('clear:key', 2, 60);
        $this->assertSame(1, $r->attempts());
        $this->assertTrue($r->isAllowed());
    }
}
