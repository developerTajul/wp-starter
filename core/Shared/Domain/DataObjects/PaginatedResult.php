<?php

declare(strict_types=1);

namespace Core\Shared\Domain\DataObjects;

use InvalidArgumentException;

/**
 * A page of results, with the numbers needed to ask for the next one.
 *
 * ## Framework-free by construction
 *
 * Nothing here knows about WordPress, a database, or a serializer. A repository
 * builds one from a slice of rows and a `COUNT(*)`; an application service maps
 * the items into response DTOs; delivery turns it into a payload. Each of those
 * steps can be tested without the others, which is the reason the class carries
 * no dependencies at all.
 *
 * ## Why `total` is a separate parameter from the slice
 *
 * The two are computed by different queries and routinely disagree: the slice is
 * this page's rows, the total is every matching row in the table. A repository
 * that passes `count($rows)` as the total reports "1 of 1" for every page and
 * hides every page but the first — with the rows themselves correct, so nothing
 * else looks wrong. Keeping them as separate, separately named parameters is what
 * makes that mistake visible at the call site.
 *
 * ## Why the arithmetic lives here
 *
 * `lastPage`, `firstItem()` and `lastItem()` are each one integer division away
 * from an off-by-one, and the wrong answer still renders as a plausible number in
 * a pagination control. Deriving them in one place means one place to get right
 * and one place to test.
 */
final readonly class PaginatedResult
{
    /**
     * The largest page a client may ask for.
     *
     * `perPage=100000000` is a request that hands one client the whole table, and
     * a query string is user input. The cap is deliberately generous — this is a
     * guard against a value that was never meant as a page size, not a policy
     * about how lists should be read.
     */
    public const MAX_PER_PAGE = 1000;

    /**
     * The smallest page size, which is also the floor for a nonsense value.
     *
     * Not zero: a `perPage` of zero reaches a `LIMIT 0` clause and returns no
     * rows, which a caller reads as "this list is empty" rather than as "the page
     * size was wrong".
     */
    public const MIN_PER_PAGE = 1;

    /**
     * This page's rows, reindexed as a list.
     *
     * @var list<mixed>
     */
    public array $items;

    /**
     * How many rows match in total, across every page.
     */
    public int $total;

    /**
     * The page being described, floored at 1.
     */
    public int $currentPage;

    /**
     * How many rows this page may hold, clamped to the allowed range.
     */
    public int $perPage;

    /**
     * @param iterable<mixed> $items       This page's rows. Any iterable, because
     *                                     a repository may hand over a generator
     *                                     lazily; it is drained here so the page
     *                                     can be counted and mapped more than once.
     * @param int             $total       Every matching row, not the slice size.
     * @param int             $perPage     Rows per page. Clamped, not refused — the
     *                                     value is usually computed rather than
     *                                     chosen, and refusing it fails a request
     *                                     that has an obvious correct reading.
     * @param int             $currentPage The page asked for. Floored at 1 for the
     *                                     same reason; `?page=0` is a URL somebody
     *                                     typed.
     *
     * @throws InvalidArgumentException When the total is negative.
     */
    public function __construct(
        iterable $items = [],
        int $total = 0,
        int $perPage = 15,
        int $currentPage = 1,
    ) {
        // Refused rather than clamped, and this is the one value treated that way.
        // A negative total is not a page size somebody typed — it is a bug in the
        // COUNT that produced it, and reading it as zero would report an empty
        // result set instead of the fault that caused it.
        if ($total < 0) {
            throw new InvalidArgumentException(sprintf(
                'A paginated total cannot be negative; got %d. A negative count means the COUNT '
                . 'that produced it is wrong, not that the result set is empty.',
                $total
            ));
        }

        $this->items = is_array($items) ? array_values($items) : array_values(iterator_to_array($items, false));
        $this->total = $total;
        $this->perPage = max(self::MIN_PER_PAGE, min($perPage, self::MAX_PER_PAGE));
        $this->currentPage = max(1, $currentPage);
    }

    /**
     * Build a page from a slice of rows and the count across all of them.
     *
     * The named factory exists so the two numbers are passed by name at every call
     * site. Positionally they are adjacent integers, and a repository that swapped
     * them would produce a page whose contents are right and whose footer is
     * nonsense.
     *
     * @param iterable<mixed> $items
     */
    public static function fromSlice(iterable $items, int $total, int $perPage = 15, int $currentPage = 1): self
    {
        return new self($items, $total, $perPage, $currentPage);
    }

    /**
     * A page with no rows, positioned where the caller asked.
     *
     * The position is kept rather than reset so that an empty page 4 still
     * reports "4 of 4" instead of implying the caller was sent back to the start.
     */
    public static function empty(int $perPage = 15, int $currentPage = 1): self
    {
        return new self([], 0, $perPage, $currentPage);
    }

    // ------------------------------------------------------------------- items

    /**
     * This page's rows, as a list.
     *
     * Reindexed in the constructor, so the keys a driver happened to use cannot
     * survive into a JSON payload where the client expects an array.
     *
     * @return list<mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * How many rows are on this page.
     */
    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    // ------------------------------------------------------------------ totals

    /**
     * How many rows match across every page.
     */
    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * The number of the final page.
     *
     * Rounded up, not truncated: 21 rows at 20 per page is two pages, and integer
     * division gives one — hiding the last row behind a control that says there is
     * nothing more to fetch. An empty result still has one page, because a client
     * rendering "page 1 of 0" has no sensible meaning.
     */
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    // ------------------------------------------------------------- item bounds

    /**
     * The 1-based number of this page's first row, or 0 when the page is empty.
     *
     * Zero rather than a computed value, because an empty page past the end would
     * otherwise report a range running backwards — "showing 1981-1960 of 340" is
     * this arithmetic with no guard on it.
     */
    public function firstItem(): int
    {
        if ($this->items === []) {
            return 0;
        }

        return ($this->currentPage - 1) * $this->perPage + 1;
    }

    /**
     * The 1-based number of this page's last row, or 0 when the page is empty.
     *
     * Capped at the total, so a short final page ends at the row it actually
     * holds rather than at `currentPage * perPage` — which the client would print
     * as "showing 41-60 of 45".
     */
    public function lastItem(): int
    {
        if ($this->items === []) {
            return 0;
        }

        return min($this->firstItem() + $this->count() - 1, $this->total);
    }

    // -------------------------------------------------------------- navigation

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    /**
     * Whether the caller asked for a page past the end.
     *
     * Separate from `hasMorePages()`, which is false for both the last page and a
     * page beyond it. A pager needs the two apart: one is "you have finished", the
     * other is "you are nowhere" — a distinction that matters after rows are
     * deleted while somebody holds a bookmark.
     */
    public function isBeyondLastPage(): bool
    {
        return $this->currentPage > $this->lastPage();
    }

    // --------------------------------------------------------------------- map

    /**
     * Rebuild the page with the same position and a new slice of items.
     *
     * The operation a mapping layer performs. Rebuilding a page by hand is how
     * `total` gets dropped and a decorated response reports its own slice size as
     * the grand total.
     *
     * @param iterable<mixed> $items
     */
    public function withItems(iterable $items): self
    {
        return new self($items, $this->total, $this->perPage, $this->currentPage);
    }

    /**
     * Transform every item, preserving the page's position and total.
     *
     * The callback receives the item and its offset *within this page*. A mapper
     * rendering alternating rows or row numbers needs to know where it is, and a
     * caller that wants a global index adds `firstItem()` — passing a global index
     * here would make the callback's answer depend on the requested page, which is
     * not what a per-row formatter means.
     *
     * @param callable(mixed, int): mixed $callback
     */
    public function map(callable $callback): self
    {
        return $this->withItems(
            array_map($callback, $this->items, array_keys($this->items))
        );
    }

    // -------------------------------------------------------------------- meta

    /**
     * The page described for a client.
     *
     * A separate block rather than fields merged into the items, because a flat
     * payload would have to reserve names a resource field might legitimately
     * want — `total` and `page` are both plausible columns on a real table.
     *
     * @return array{
     *     total: int,
     *     per_page: int,
     *     current_page: int,
     *     last_page: int,
     *     from: int,
     *     to: int,
     *     has_more_pages: bool,
     * }
     */
    public function meta(): array
    {
        return [
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage(),
            'from' => $this->firstItem(),
            'to' => $this->lastItem(),
            'has_more_pages' => $this->hasMorePages(),
        ];
    }
}
