<?php
declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

use DateInterval;
use DateTimeInterface;

/**
 * A key-value store that is allowed to forget.
 *
 * ## Why this port exists, and why it is deliberately small
 *
 * The business layer needs to say "keep this for five minutes" and nothing more.
 * It must not know whether that lands in a WordPress transient, an object cache
 * with Redis behind it, an APCu segment, or an array in a test — because if it
 * did, every service and use case that read a cached value would need a
 * WordPress-shaped test double, and the seam that keeps `Core\` free of the CMS
 * would be gone.
 *
 * So the interface states the *guarantee* a cache gives and leaves the storage
 * to the adapter, the same shape as {@see RateLimiterContract}. Five methods,
 * no more: `get`, `set`, `forget`, `has`, `remember`. A wider interface —
 * `increment`, `tags`, `flush` — would be a list of features each adapter must
 * implement or refuse, and the refusals would be the interesting part.
 *
 * ## The one property every implementation must meet: a value may vanish
 *
 * A cache is not storage. Everything in it may be evicted at any moment, for
 * reasons outside the program — the TTL expired, another process needed the
 * memory, the backend was flushed during a deploy. **A caller must be able to
 * lose any value at any time and still be correct.** If losing an entry changes
 * an answer, the entry was not a cache entry and does not belong here.
 *
 * That is why the port has no `flush()`: a cache the application itself clears
 * is a cache the application is treating as authoritative.
 *
 * ## `false` is not a representable value, and that is a narrowing on purpose
 *
 * Laravel's cache facade stores `false` and returns it; a miss and a stored
 * `false` are told apart by a sentinel object. This interface does not do that.
 * **`null` means "not present", and an implementation must not store `null`.**
 *
 * The reason is that under the hood there are two WordPress stores with two
 * different miss signals — `get_transient()` returns `false`, and
 * `wp_cache_get()` returns `false` plus a by-reference `$found` flag — and
 * supporting a stored `false` means getting the second one right at every call
 * site. A single forgotten `$found` turns "no entry" into "the value `false`",
 * which reads as a cache hit and is invisible until something acts on it.
 * Refusing to store `null` collapses the ambiguity at the port instead of
 * defending it in every adapter.
 *
 * **A caller that needs to cache a boolean caches something else** — the string
 * `'0'`/`'1'`, a wrapper array — or does not cache it. That is a real
 * inconvenience and it is the trade being made: the failure it prevents is
 * silent and the inconvenience is not.
 */
interface CacheInterface
{
    /**
     * Read a value, or null when it is not there.
     *
     * Null covers both "never set" and "expired", because they are the same
     * thing to every caller: the value is not available and must be computed
     * again. Trying to distinguish them would push eviction reasons up into
     * business logic that cannot act on them anyway.
     *
     * @param string $key A key already namespaced by the caller's domain, e.g.
     *                    `'lead:42'`. The implementation adds the installation
     *                    prefix; a caller must not, because only the adapter
     *                    knows what that prefix is.
     *
     * @return mixed The stored value, or null. Never null for a value that was
     *               stored — see the interface docblock.
     */
    public function get(string $key): mixed;

    /**
     * Write a value, replacing anything already under the key.
     *
     * @param string                $key   As {@see get()}.
     * @param mixed                 $value The value to store. **Not null** — see
     *                                     the interface docblock. An implementation
     *                                     that receives null must refuse rather
     *                                     than silently store an absence.
     * @param DateInterval|int|null $ttl   How long it lasts. An `int` is a number
     *                                     of **seconds**; null means the
     *                                     implementation's default. A `DateTimeInterface`
     *                                     is deliberately not accepted: an absolute
     *                                     expiry is a time, and a time is a thing a
     *                                     caller that meant a duration passes by
     *                                     accident when it passes a timestamp.
     */
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): void;

    /**
     * Remove a key, whether or not it was there.
     *
     * Removing a key that does not exist is not an error. Callers use this to
     * invalidate *after* a write, when whether anything was cached is something
     * they cannot know and should not have to.
     */
    public function forget(string $key): void;

    /**
     * Whether a value is present.
     *
     * A convenience over `get() !== null`, and worth having as a named method
     * because it reads as a question about presence rather than about the value
     * — which is the actual question at a call site that is deciding whether to
     * compute something.
     *
     * An implementation must not answer this by calling `get()` and discarding
     * the result if it can do better; an adapter that can check existence
     * without fetching the payload should.
     */
    public function has(string $key): bool;

    /**
     * Return the cached value, computing and storing it when there is none.
     *
     * The main entry point, because it is the one form that cannot be used
     * wrongly. Reading `get()`, testing for null, then calling `set()` is the
     * same logic written out — and written out is where the stale-read and
     * forget-to-store bugs live.
     *
     * **`$ttl` applies only when the value is computed.** On a hit the existing
     * entry keeps the expiry it was written with. Refreshing it on every read
     * would make a hot key immortal, which is how a cache stops being one; a
     * caller that genuinely wants sliding expiry writes `forget()` then
     * `remember()`.
     *
     * @param callable():mixed $callback Computes the value on a miss. Its return
     *                                   must not be null — returning null is the
     *                                   one thing this method cannot store, and
     *                                   an implementation must raise rather than
     *                                   treat it as a miss and call again, which
     *                                   would hide a broken callback behind a
     *                                   cache that never holds anything.
     *
     * @return mixed The cached or newly computed value.
     */
    public function remember(string $key, callable $callback, DateInterval|int|null $ttl = null): mixed;
}
