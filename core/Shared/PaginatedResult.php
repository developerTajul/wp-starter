<?php
declare(strict_types=1);

namespace Core\Shared;

/**
 * A page of results, with the numbers needed to ask for the next one.
 *
 * ## Why this is not an array
 *
 * A repository that returns `Note[]` has thrown away three things the caller
 * cannot reconstruct: how many items exist *in total*, which slice it is looking
 * at, and whether there is more. A client that receives twenty notes and no
 * count cannot draw "1-20 of 340", cannot offer a next-page link, and cannot
 * tell "that is all of them" from "that is the first page". Recovering any of
 * those means a second, differently-shaped query — which is how a controller
 * ends up counting rows itself.
 *
 * So a page carries its own metadata. The list is the payload; the rest is what
 * makes the payload navigable.
 *
 * ## Why this is not the framework's paginator
 *
 * `core/` is framework-agnostic, and a paginator is exactly the class of object
 * a framework provides — the surrounding application hands back an array and a
 * row count, an ORM hands back a length-aware paginator object, and the two are
 * not interchangeable. A domain service that returned either would be bound to
 * the one its infrastructure happened to use.
 *
 * This is the shape both of them reduce to. The service computes it, the
 * repository produces it, and the layer that knows about HTTP turns it into a
 * response — including, if the project uses one, a framework's own pagination
 * format. The translation lives at the edge, where the knowledge is.
 *
 * ## Invariants
 *
 * `$perPage`, `$currentPage` and `$total` are validated and clamped at
 * construction, because every one of them reaches a database LIMIT or a JSON
 * payload. A `perPage` of `0` is an unbounded query waiting to happen, a
 * negative `currentPage` produces a negative offset that MySQL rejects and
 * SQLite silently treats as zero, and a negative `total` makes every derived
 * number below wrong. Each is refused or corrected at the point it entered,
 * where the caller can still be identified, rather than at the compute site.
 *
 * ## Immutability
 *
 * Final and readonly with `with*()` methods, the same convention as the entities
 * this wraps. A page is a value: it is handed to a view, a response, and possibly
 * a cache, and a mutation anywhere would change it for all three. {@see withItems()}
 * exists because a layer that decorates each item — a resource mapper, a
 * permission filter — needs to produce a page with the same position and a
 * different payload, and rebuilding one by hand is how `total` gets left behind.
 *
 * @template T
 */
final class PaginatedResult
{
    /**
     * The largest page this class will describe.
     *
     * Not a rule about what an application may fetch — a repository is free to
     * run an unbounded query — but a ceiling on what a *page* can claim to be.
     * Without one, `perPage` is an unbounded integer straight from a query
     * string, and `perPage=100000000` is a request that hands one client the
     * whole table. Clamped rather than refused because the value is usually
     * computed (a default times a client-supplied page size) rather than chosen,
     * and a hard failure there would take out a legitimate request over an
     * arithmetic edge case.
     */
    public const MAX_PER_PAGE = 1000;

    /**
     * @var list<T>
     */
    private readonly array $items;

    private readonly int $total;

    private readonly int $perPage;

    private readonly int $currentPage;

    private readonly int $lastPage;

    /**
     * @param iterable<T> $items
     * @param int         $total   Rows matching the query across every page — not
     *                             the size of `$items`. This is the count a driver's
     *                             found-rows reports, and the one number a caller
     *                             cannot derive from the page it holds.
     * @param int         $perPage How many were asked for. Clamped to
     *                             {@see MAX_PER_PAGE} and floored at 1.
     * @param int         $currentPage 1-based. Clamped to at least 1.
     *
     * @throws \InvalidArgumentException When `$total` is negative. Unlike the other
     *                                   two this is not clamped: `perPage` and
     *                                   `currentPage` arrive from user input and
     *                                   have a correct interpretation, but a
     *                                   negative total is a bug in the query that
     *                                   produced it, and quietly reading it as zero
     *                                   hides a broken COUNT behind an empty page.
     */
    public function __construct(
        iterable $items = [],
        int $total = 0,
        int $perPage = 15,
        int $currentPage = 1
    ) {
        if ($total < 0) {
            throw new \InvalidArgumentException(sprintf(
                'A paginated total cannot be negative; %d was given. The count query that '
                . 'produced it is wrong, and treating it as zero would report an empty result '
                . 'set rather than the fault.',
                $total
            ));
        }

        $this->items = is_array($items) ? array_values($items) : array_values(iterator_to_array($items, false));

        $this->perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $this->currentPage = max(1, $currentPage);
        $this->total = $total;

        // Derived once, here, rather than recomputed by each accessor. `lastPage`
        // is the one number a caller can get subtly wrong on their own — the
        // off-by-one is `(int) ceil($total / $perPage)` and dividing integers
        // instead rounds down, which turns a 21-row result at 20 per page into
        // one page and hides the second.
        $this->lastPage = $total === 0 ? 1 : (int) ceil($total / $this->perPage);
    }

    /**
     * An empty page.
     *
     * A named constructor because "no rows" still has a shape: callers that
     * early-return a `PaginatedResult` should not each have to decide what
     * `total`, `perPage` and `currentPage` mean for nothing. They mean the query
     * matched nothing, and the page the client is on is still the page they asked
     * for — so that number is kept.
     *
     * @return self<never>
     */
    public static function empty(int $perPage = 15, int $currentPage = 1): self
    {
        return new self([], 0, $perPage, $currentPage);
    }

    /**
     * Build a page from an already-fetched slice and a total.
     *
     * The pairing a repository performs constantly: the driver returns the rows
     * for this page and, separately, the count of all of them. Named so the two
     * numbers are visibly distinct at the call site — a repository that passes
     * `count($rows)` as the total is the single most common way a paginated API
     * reports "1 of 1" for every page.
     *
     * @template TItem
     *
     * @param iterable<TItem> $items
     *
     * @return self<TItem>
     */
    public static function fromSlice(iterable $items, int $total, int $perPage, int $currentPage): self
    {
        return new self($items, $total, $perPage, $currentPage);
    }

    /**
     * The items on this page.
     *
     * A list, not a map: the keys a database returns are an artefact of how the
     * rows were fetched, and a JSON payload built from them is an object where a
     * client expects an array.
     *
     * @return list<T>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Rows matching the query across every page.
     *
     * Named for what it counts rather than `count()`, which reads as "how many
     * are in `items()`" — a distinction a caller gets wrong silently, because
     * both are plausible numbers and the wrong one still renders.
     */
    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * The 1-based page this is.
     *
     * Note that it is the page *asked for*, not the page the items imply. A
     * request for page 99 of a 3-page result returns `currentPage() === 99` and
     * an empty list, because that is what was requested and a client building
     * "previous" links needs to know it.
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * The number of the final page, never less than 1.
     *
     * 1 rather than 0 for an empty result, so a client's "page N of M" reads
     * `1 of 1` instead of `1 of 0`.
     */
    public function lastPage(): int
    {
        return $this->lastPage;
    }

    /**
     * How many items are on this page.
     *
     * The length of the slice, not the total — see {@see total()}.
     */
    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Whether this is the page the client asked for.
     *
     * `hasMorePages()` alone cannot answer "should I show a next link", because a
     * request past the end has none and yet is not a valid terminus either. A
     * client that cares about the difference — one that 404s a page beyond the
     * range rather than rendering an empty table — asks this.
     */
    public function isBeyondLastPage(): bool
    {
        return $this->currentPage > $this->lastPage;
    }

    /**
     * The 1-based number of the first item on this page, or 0 when empty.
     *
     * 0 rather than `currentPage * perPage` for an empty page, so a client
     * rendering "showing X-Y of Z" does not print "showing 1981-1960 of 340".
     */
    public function firstItem(): int
    {
        return $this->items === [] ? 0 : ($this->currentPage - 1) * $this->perPage + 1;
    }

    /**
     * The 1-based number of the last item on this page, or 0 when empty.
     */
    public function lastItem(): int
    {
        return $this->items === [] ? 0 : $this->firstItem() + count($this->items) - 1;
    }

    /**
     * A page with the same position and a different payload.
     *
     * The one mutation a page legitimately needs, and the reason it is a whole
     * method rather than a new constructor call at each site: replacing the items
     * while keeping `total`, `perPage` and `currentPage` is the operation a
     * mapping layer performs, and doing it by hand is how `total` gets dropped
     * and a decorated response reports its own slice size as the grand total.
     *
     * @template TNew
     *
     * @param iterable<TNew> $items
     *
     * @return self<TNew>
     */
    public function withItems(iterable $items): self
    {
        return new self($items, $this->total, $this->perPage, $this->currentPage);
    }

    /**
     * The pagination block a client receives alongside the items.
     *
     * A separate method rather than keys merged into a `toArray()`, because the
     * items and the metadata are two different payloads that a JSON response
     * keeps apart: the items are the resource collection, and this is the wrapper
     * around it. A single flat array would have to reserve names that a resource
     * field might legitimately want — `total` and `page` are both plausible
     * columns.
     *
     * The key names are `snake_case` to match the resource payloads this sits
     * beside, and `from`/`to` are named rather than `first`/`last` because both
     * of those read as *items* — the first item is not the number `1`.
     *
     * @return array{
     *     total: int, per_page: int, current_page: int, last_page: int,
     *     from: int, to: int, has_more_pages: bool
     * }
     */
    public function meta(): array
    {
        return [
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
            'from' => $this->firstItem(),
            'to' => $this->lastItem(),
            'has_more_pages' => $this->hasMorePages(),
        ];
    }

    /**
     * Map every item through a callback, preserving the page's position.
     *
     * `array_map` on the items, not a re-query — the metadata is a property of
     * the query, not of the rows, so mapping must not change any of it.
     *
     * @template TNew
     *
     * @param callable(T, int): TNew $map Receives the item and its 0-based offset
     *                                    within the page, which a mapper needs
     *                                    when the output depends on position
     *                                    (alternating styles, row numbers).
     *
     * @return self<TNew>
     */
    public function map(callable $map): self
    {
        $mapped = [];

        foreach ($this->items as $index => $item) {
            $mapped[] = $map($item, $index);
        }

        return new self($mapped, $this->total, $this->perPage, $this->currentPage);
    }
}
