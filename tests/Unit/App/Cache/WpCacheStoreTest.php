<?php
declare(strict_types=1);

namespace Tests\Unit\App\Cache;

use App\Cache\WpCacheStore;
use DateInterval;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\WpStub;

/**
 * The cache store, on both of the paths it chooses between.
 *
 * ## Why every test here runs twice
 *
 * `WpCacheStore` routes to the object cache or to transients depending on
 * {@see wp_using_ext_object_cache()}, and the two branches have **different miss
 * signals** — the object cache has a by-reference `$found`, transients do not.
 * A suite that only exercised the default branch would leave the other one
 * unexecuted, and the branch it left unexecuted is the one that behaves
 * differently under a stored falsy value.
 *
 * So the store-behaviour tests are written once in a private method and run from
 * two public tests, one per branch. `test_a_stored_value_survives_a_read` and
 * `test_a_stored_value_survives_a_read_with_a_persistent_object_cache` run the
 * same assertions against different storage, and a change that fixes one branch
 * while breaking the other fails one of them by name.
 *
 * ## Why the fallback is the default and the object cache is the special case
 *
 * {@see WpStub::$usingExtObjectCache} defaults to `false`, and a test has to opt
 * *in* to the object-cache branch. That is the correct way round: a stock
 * WordPress has no persistent object cache, so the fallback is what almost every
 * install runs, and it is the branch a store that wrongly assumed persistence
 * would break in. Opting in to the other one makes the assumption visible in the
 * test that depends on it.
 *
 * ## The `$found` parameter is what this file exists to pin down
 *
 * `wp_cache_get()` returns `false` for a miss and `false` for a stored `false`,
 * and `$found` is the only thing that separates them. The stub writes it on a
 * miss as well as a hit — a stub that only wrote it on a hit would leave the
 * caller's variable holding whatever it last had, which reads as a hit, and
 * `test_a_miss_is_not_confused_with_a_stored_value` would pass over an
 * implementation that could not tell the difference. That is the failure mode
 * the stub docblock in `tests/stubs/wordpress.php` calls out, and this is the
 * test that would expose it.
 */
final class WpCacheStoreTest extends TestCase
{
    private const PREFIX = 'test_prefix_';

    protected function setUp(): void
    {
        // Reset before, not after. A test that leaves the object cache populated
        // would otherwise leak into whichever test happens to run next, and the
        // symptom — a value the store never wrote — appears in the wrong file.
        WpStub::reset();
    }

    private function store(): WpCacheStore
    {
        return new WpCacheStore(self::PREFIX);
    }

    /**
     * A miss on the default (transient-backed) path returns null.
     */
    public function test_a_missing_key_reads_as_null(): void
    {
        $this->assertNull($this->store()->get('absent'));
    }

    /**
     * A written value reads back, on the transient path.
     */
    public function test_a_stored_value_survives_a_read(): void
    {
        $this->assertStoredValueSurvives($this->store());
    }

    /**
     * The same assertions against the persistent-object-cache branch.
     *
     * The two branches are different code with different miss signals, so
     * passing the first test says nothing about this one.
     */
    public function test_a_stored_value_survives_a_read_with_a_persistent_object_cache(): void
    {
        WpStub::$usingExtObjectCache = true;

        $this->assertStoredValueSurvives($this->store());
    }

    /**
     * The shared body of the two branch tests, so neither can drift from the other.
     */
    private function assertStoredValueSurvives(WpCacheStore $store): void
    {
        $store->set('lead:42', ['id' => 42, 'name' => 'Ada']);

        $this->assertSame(['id' => 42, 'name' => 'Ada'], $store->get('lead:42'));
        $this->assertTrue($store->has('lead:42'));
    }

    /**
     * The object-cache branch really writes to the object cache.
     *
     * Asserted against the recorded calls rather than only against a successful
     * read-back, because a read that round-trips through the *wrong* store can
     * still return the right value — both stores hold the same key here. The
     * write calls are the only evidence of which one was chosen.
     */
    public function test_a_write_with_a_persistent_object_cache_goes_to_the_object_cache(): void
    {
        WpStub::$usingExtObjectCache = true;

        $this->store()->set('lead:42', 'Ada');

        $this->assertCount(1, WpStub::$objectCacheWrites, 'The object cache should have been written.');
        $this->assertSame([], WpStub::$transients, 'Transients must not be touched when a persistent cache exists.');
    }

    /**
     * With no persistent backend, the write goes to transients and not the cache.
     *
     * The mirror of the previous test, and the more important of the two: on a
     * stock WordPress `wp_cache_set()` succeeds and stores nothing that outlives
     * the request, so a store that wrote there would look like it worked and
     * cache nothing.
     */
    public function test_a_write_without_a_persistent_object_cache_goes_to_transients(): void
    {
        $this->store()->set('lead:42', 'Ada');

        $this->assertSame([], WpStub::$objectCacheWrites, 'The object cache must not be written without a persistent backend.');
        $this->assertCount(1, WpStub::$transientWrites);
        $this->assertSame('Ada', WpStub::$transients[self::PREFIX . 'cache:lead:42'] ?? null);
    }

    /**
     * The key WordPress actually sees is namespaced with the installation prefix.
     *
     * Two installs sharing one Redis instance is a real deployment, and without
     * the prefix the second install reads the first's entries — with no error,
     * because the keys match.
     */
    public function test_the_stored_key_is_namespaced_with_the_installation_prefix(): void
    {
        $this->store()->set('lead:42', 'Ada');

        $written = WpStub::$transientWrites[0]['key'];

        $this->assertStringStartsWith(self::PREFIX, $written);
        $this->assertStringContainsString('lead:42', $written);
    }

    /**
     * A prefix that is empty is refused at construction.
     *
     * Not a cosmetic check: an empty prefix is the difference between two sites
     * sharing a cache backend reading each other's data and not, and it is
     * invisible at the call site.
     */
    public function test_an_empty_prefix_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/prefix cannot be empty/');

        new WpCacheStore('');
    }

    /**
     * A key long enough to exceed WordPress's limit is hashed, not truncated.
     *
     * Truncation is the failure this prevents: two long keys sharing their first
     * 172 characters would map onto one storage key, and the second write would
     * overwrite the first while both callers believed they had their own entry.
     * Hashing keeps them apart.
     */
    public function test_an_over_long_key_is_hashed_rather_than_truncated(): void
    {
        $store = $this->store();

        // Two keys identical for well past the 172-character limit, differing
        // only at the very end — exactly the pair truncation would collide.
        $suffix = str_repeat('x', 200);

        $store->set('key-a-' . $suffix, 'first');
        $store->set('key-b-' . $suffix, 'second');

        $this->assertSame('first', $store->get('key-a-' . $suffix));
        $this->assertSame('second', $store->get('key-b-' . $suffix));

        foreach (WpStub::$transientWrites as $write) {
            $this->assertLessThanOrEqual(
                172,
                strlen($write['key']),
                'A stored key must respect WordPress\'s length limit.'
            );
        }
    }

    /**
     * A stored value that is falsy but not `false` reads back correctly.
     *
     * `0` and `'0'` and `[]` are the values that survive the miss signal, and
     * they are the ones a caller is most likely to cache — a count of zero, an
     * empty result set. This asserts the port can carry them, which is the
     * positive half of refusing `false`.
     */
    public function test_falsy_values_other_than_false_can_be_cached(): void
    {
        $store = $this->store();

        $store->set('zero', 0);
        $store->set('empty-string', '');
        $store->set('empty-array', []);

        $this->assertSame(0, $store->get('zero'));
        $this->assertSame('', $store->get('empty-string'));
        $this->assertSame([], $store->get('empty-array'));
    }

    /**
     * Storing `false` is refused, because it could never be read back.
     *
     * `get_transient()` reports a miss by returning `false` and offers no way to
     * distinguish that from a stored `false`. The write would appear to succeed
     * and every read would report a miss, so the entry would cost a write on
     * every request and return nothing — a bug with no symptom of its own.
     */
    public function test_storing_false_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cannot cache false/');

        $this->store()->set('flag', false);
    }

    /**
     * Storing null is refused, because null is the miss signal.
     */
    public function test_storing_null_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cannot cache null/');

        $this->store()->set('flag', null);
    }

    /**
     * `forget()` removes the entry, and removing an absent one is not an error.
     */
    public function test_forget_removes_a_stored_value(): void
    {
        $store = $this->store();

        $store->set('lead:42', 'Ada');
        $store->forget('lead:42');

        $this->assertNull($store->get('lead:42'));
        $this->assertFalse($store->has('lead:42'));

        // Idempotent: a caller invalidating after a write cannot know whether
        // anything was cached, and should not have to.
        $store->forget('lead:42');
        $this->assertNull($store->get('lead:42'));
    }

    /**
     * `forget()` clears the key from both stores, not only the active one.
     *
     * A site can gain or lose its persistent object cache between the request
     * that wrote an entry and the request that invalidates it. If `forget()` only
     * touched the currently-active store, the entry would sit in the other one
     * and reappear the moment the configuration changed back — a stale value that
     * outlives the code that invalidated it.
     */
    public function test_forget_clears_both_stores(): void
    {
        $store = $this->store();

        // Written to the object cache, under a persistent backend...
        WpStub::$usingExtObjectCache = true;
        $store->set('lead:42', 'stale');

        // ...then invalidated in a request where the backend is gone.
        WpStub::$usingExtObjectCache = false;
        $store->forget('lead:42');

        $this->assertSame(
            [],
            WpStub::$objectCache['wp_mini_laravel'] ?? [],
            'forget() must clear the object cache as well as transients.'
        );

        // And with the backend back, the value must not return.
        WpStub::$usingExtObjectCache = true;
        $this->assertNull($store->get('lead:42'), 'A stale object-cache entry survived forget().');
    }

    /**
     * `remember()` computes on a miss and stores the result.
     */
    public function test_remember_computes_once_and_then_serves_from_cache(): void
    {
        $store = $this->store();
        $calls = 0;
        $compute = static function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        $this->assertSame('computed', $store->remember('lead:42', $compute));
        $this->assertSame('computed', $store->remember('lead:42', $compute));

        $this->assertSame(1, $calls, 'The callback must run once; the second call should be a cache hit.');
    }

    /**
     * `remember()` works on the object-cache branch too, where the miss signal
     * is `$found` rather than a `false` return.
     */
    public function test_remember_computes_once_with_a_persistent_object_cache(): void
    {
        WpStub::$usingExtObjectCache = true;

        $store = $this->store();
        $calls = 0;
        $compute = static function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        $this->assertSame('computed', $store->remember('lead:42', $compute));
        $this->assertSame('computed', $store->remember('lead:42', $compute));

        $this->assertSame(1, $calls);
    }

    /**
     * A callback returning null raises rather than silently never caching.
     *
     * Returning null and storing nothing would make every later call re-run the
     * callback while `remember()` kept reporting success — a cache that costs the
     * full price of a miss on every request and shows up in no metric.
     */
    public function test_remember_refuses_a_null_result(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/returned null/');

        $this->store()->remember('lead:42', static fn (): mixed => null);
    }

    /**
     * A TTL in seconds reaches the store as that many seconds.
     */
    public function test_an_integer_ttl_is_passed_through_as_seconds(): void
    {
        $this->store()->set('lead:42', 'Ada', 300);

        $this->assertSame(300, WpStub::$transientWrites[0]['expiration']);
    }

    /**
     * A `DateInterval` TTL is converted to seconds.
     */
    public function test_a_date_interval_ttl_is_converted_to_seconds(): void
    {
        $this->store()->set('lead:42', 'Ada', new DateInterval('PT5M'));

        $this->assertSame(300, WpStub::$transientWrites[0]['expiration']);
    }

    /**
     * A month-long interval converts by calendar arithmetic, not by field sum.
     *
     * `new DateInterval('P1M')` has `$m === 1` and `$d === 0`, so an
     * implementation that summed the fields would turn "one month" into zero
     * seconds — and zero, passed on to `set_transient()`, means *no expiry at
     * all*. The bug would invert the intent silently.
     */
    public function test_a_month_long_interval_is_not_read_as_zero(): void
    {
        $this->store()->set('lead:42', 'Ada', new DateInterval('P1M'));

        $this->assertGreaterThan(
            2_000_000,
            WpStub::$transientWrites[0]['expiration'],
            'P1M must convert to roughly a month of seconds, not to zero.'
        );
    }

    /**
     * No TTL means the store's default, not "no expiry".
     *
     * WordPress reads an expiration of 0 as *cache this forever*, so passing a
     * caller's silence through as 0 would make the safest-looking call the one
     * with the longest lifetime.
     */
    public function test_an_omitted_ttl_uses_the_store_default_rather_than_no_expiry(): void
    {
        $this->store()->set('lead:42', 'Ada');

        $this->assertGreaterThan(0, WpStub::$transientWrites[0]['expiration']);
    }

    /**
     * A non-positive TTL is refused for exactly the reason above.
     */
    public function test_a_zero_ttl_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least 1 second/');

        $this->store()->set('lead:42', 'Ada', 0);
    }

    /**
     * `has()` does not report a cached empty array as absent.
     *
     * The trap in a naive implementation: `has()` written as a truthiness test
     * (`(bool) $this->get($key)`) reports `false` for a cached empty set, so a
     * caller that used `has()` to decide whether to recompute would recompute
     * every time — and for a query that genuinely returns nothing, forever.
     */
    public function test_has_reports_a_cached_empty_array_as_present(): void
    {
        $store = $this->store();
        $store->set('empty-result', []);

        $this->assertTrue($store->has('empty-result'));
    }
}
