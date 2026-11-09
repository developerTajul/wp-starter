<?php
declare(strict_types=1);

namespace App\Cache;

use Core\Shared\Domain\Contracts\CacheInterface;
use DateInterval;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cache backed by the WordPress object cache, falling back to transients.
 *
 * Object cache entries only survive the request when a persistent backend is
 * installed, so {@see wp_using_ext_object_cache()} is checked per call rather
 * than cached; transients survive everywhere but write a row to `options`.
 * Asking per call is what keeps both branches reachable.
 *
 * Keys are `app.prefix` + `cache:` + key, hashed when over 172 characters
 * (WordPress's limit). The `cache:` marker keeps transients and object-cache
 * entries apart, since transients have no group. Hashing rather than truncating
 * avoids mapping two long keys onto one.
 *
 * `get()` returns null for a miss; `set()` refuses null, because both stores
 * already return false for a miss and a third absent value would make the
 * return type ambiguous. Callers caching a nullable value cache a wrapper.
 *
 * Not implemented: tags, `flush()`, atomic increment, `forever()`. No backend
 * supports all of them, and the gaps would surface as "works here, not there".
 *
 * @implements CacheInterface
 */
final class WpCacheStore implements CacheInterface
{
    /** Group every entry is written under; a delete against the wrong group is a silent no-op. */
    private const GROUP = 'wp_mini_laravel';

    /** WordPress's limit on transient names and object-cache keys. Some backends truncate instead of failing. */
    private const MAX_KEY_LENGTH = 172;

    /** Keeps this store's transients apart from other writers sharing the prefix; transients have no group. */
    private const KEY_MARKER = 'cache:';

    /** Default lifetime, one hour. Long enough to be useful, short enough that a broken invalidation heals itself. */
    private const DEFAULT_TTL = 3600;

    /**
     * @param string $prefix Installation prefix from `app.prefix`, keeping two sites on one Redis apart.
     */
    public function __construct(private readonly string $prefix)
    {
        // An empty prefix would let two installs share a cache silently.
        if ($this->prefix === '') {
            throw new InvalidArgumentException(
                'The cache prefix cannot be empty: it is what separates this install\'s cache keys '
                . 'from another install sharing the same object-cache backend. Check that `app.prefix` '
                . 'is set.'
            );
        }
    }

    public function get(string $key): mixed
    {
        $key = $this->key($key);

        if ($this->hasPersistentObjectCache()) {
            $found = false;
            $value = wp_cache_get($key, self::GROUP, $found);

            // `$found` separates an absent key from one holding false; comparing
            // the value alone cannot.
            return $found ? $value : null;
        }

        $value = get_transient($key);

        // No `$found` equivalent here: get_transient() reports a miss and a
        // stored false identically.
        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): void
    {
        if ($value === null) {
            throw new InvalidArgumentException(
                'Cannot cache null under "' . $key . '": a cache miss is reported as null, so a stored '
                . 'null would be indistinguishable from an absent entry on every read. Cache a wrapper '
                . 'array or a sentinel string instead.'
            );
        }

        // Both stores report a miss by returning false, so a stored false would
        // read back as absent on every call.
        if ($value === false) {
            throw new InvalidArgumentException(
                'Cannot cache false under "' . $key . '": both WordPress stores report a miss by '
                . 'returning false, so a stored false can never be read back and the entry would look '
                . 'permanently missing. Cache 0/1 or an empty array instead.'
            );
        }

        $key = $this->key($key);
        $seconds = $this->seconds($ttl);

        if ($this->hasPersistentObjectCache()) {
            wp_cache_set($key, $value, self::GROUP, $seconds);

            return;
        }

        set_transient($key, $value, $seconds);
    }

    public function forget(string $key): void
    {
        $key = $this->key($key);

        // Both stores, not just the active one: the backend can change between a
        // write and a delete, leaving the entry in the other store.
        wp_cache_delete($key, self::GROUP);
        delete_transient($key);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function remember(string $key, callable $callback, DateInterval|int|null $ttl = null): mixed
    {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();

        // Returning null without storing would re-run the callback on every call.
        if ($value === null) {
            throw new RuntimeException(
                'The callback for "' . $key . '" returned null, which cannot be cached. If the computed '
                . 'value is genuinely absent, cache a sentinel or do not cache this at all — returning null '
                . 'here would make every later call re-run the callback.'
            );
        }

        $this->set($key, $value, $ttl);

        return $value;
    }

    /** Whether a persistent object cache backs `wp_cache_*`. Read per call, not memoised. */
    private function hasPersistentObjectCache(): bool
    {
        return wp_using_ext_object_cache();
    }

    /**
     * Turn a caller's key into a namespaced, length-safe storage key.
     *
     * @see MAX_KEY_LENGTH for why an over-long key is hashed rather than cut.
     */
    private function key(string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('A cache key cannot be empty.');
        }

        $namespaced = $this->prefix . self::KEY_MARKER . $key;

        if (strlen($namespaced) <= self::MAX_KEY_LENGTH) {
            return $namespaced;
        }

        // `sha256` over the *namespaced* key, so the prefix is still part of what
        // is hashed and two installs with different prefixes hash differently.
        // The readable head is kept in front of the digest purely so a cache dump
        // shows which key family an entry belongs to.
        return $this->prefix . 'sha256:' . hash('sha256', $namespaced);
    }

    /**
     * Normalise the three TTL spellings into whole seconds.
     *
     * A `DateInterval` is converted through a reference date rather than by
     * reading `$i->d`, `$i->h` and so on: an interval of `P1M` has no fixed
     * number of days, and `new DateInterval('P1M')` has `$d === 0`, so summing
     * the fields would silently turn "one month" into zero seconds.
     */
    private function seconds(DateInterval|int|null $ttl): int
    {
        if ($ttl === null) {
            return self::DEFAULT_TTL;
        }

        if ($ttl instanceof DateInterval) {
            $epoch = new \DateTimeImmutable('@0');

            return $epoch->add($ttl)->getTimestamp();
        }

        // A non-positive TTL is refused rather than passed on. `set_transient()`
        // treats an expiration of 0 as *no expiry*, and this store's contract is
        // that a default applies when the caller says nothing — so a computed
        // zero would silently mean "forever", the opposite of what a caller who
        // computed it almost certainly intended. A negative TTL means the caller
        // wants the key gone, and `forget()` is the method for that.
        if ($ttl < 1) {
            throw new InvalidArgumentException(
                'A cache TTL must be at least 1 second; got ' . $ttl . '. Note that 0 does not mean '
                . '"expire now" — WordPress reads it as no expiry at all. Use forget() to remove a key.'
            );
        }

        return $ttl;
    }
}
