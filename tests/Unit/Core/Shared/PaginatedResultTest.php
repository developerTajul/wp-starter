<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Shared;

use Core\Shared\Domain\DataObjects\PaginatedResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The page value object, on its own.
 *
 * The arithmetic here is the part worth pinning down: `lastPage`, `firstItem`
 * and `lastItem` are each one integer-division away from an off-by-one that a
 * caller cannot see, because the wrong answer still renders as a plausible
 * number in a pagination control.
 */
final class PaginatedResultTest extends TestCase
{
    // ------------------------------------------------------------------ the math

    /**
     * The off-by-one that motivates the class.
     *
     * 21 rows at 20 per page is two pages. Integer division gives one, and one
     * page for a 21-row result hides the last row behind a control that says
     * there is nothing more to fetch.
     */
    public function test_last_page_rounds_up_rather_than_down(): void
    {
        $page = new PaginatedResult(range(1, 20), total: 21, perPage: 20, currentPage: 1);

        $this->assertSame(2, $page->lastPage());
    }

    /**
     * @param int $total
     * @param int $perPage
     * @param int $expected
     *
     * @dataProvider lastPageCases
     */
    public function test_last_page_is_derived_from_the_total(int $total, int $perPage, int $expected): void
    {
        $page = new PaginatedResult([], total: $total, perPage: $perPage, currentPage: 1);

        $this->assertSame($expected, $page->lastPage());
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function lastPageCases(): array
    {
        return [
            'an exact multiple does not gain a page' => [40, 20, 2],
            'one row over gains a page' => [41, 20, 3],
            'one row under does not' => [39, 20, 2],
            'a single page' => [5, 20, 1],
            'a single row' => [1, 20, 1],
            'one per page' => [7, 1, 7],
        ];
    }

    /**
     * An empty result has one page, not zero. A client rendering "page N of M"
     * prints "1 of 0" for the other answer.
     */
    public function test_an_empty_result_has_one_page_rather_than_zero(): void
    {
        $page = PaginatedResult::empty();

        $this->assertSame(0, $page->total());
        $this->assertSame(1, $page->lastPage());
        $this->assertTrue($page->isEmpty());
    }

    public function test_total_is_the_count_across_every_page_not_the_size_of_this_one(): void
    {
        $page = new PaginatedResult(range(1, 20), total: 340, perPage: 20, currentPage: 1);

        $this->assertSame(340, $page->total());
        $this->assertSame(20, $page->count());
    }

    // ---------------------------------------------------------------- item bounds

    public function test_the_first_and_last_item_numbers_describe_this_page(): void
    {
        $page = new PaginatedResult(range(1, 20), total: 340, perPage: 20, currentPage: 3);

        $this->assertSame(41, $page->firstItem());
        $this->assertSame(60, $page->lastItem());
    }

    /**
     * A short final page must end at the last row it actually holds, not at
     * `currentPage * perPage`, or the client prints "showing 41-60 of 45".
     */
    public function test_a_short_final_page_ends_at_its_own_last_row(): void
    {
        $page = new PaginatedResult(range(1, 5), total: 45, perPage: 20, currentPage: 3);

        $this->assertSame(41, $page->firstItem());
        $this->assertSame(45, $page->lastItem());
    }

    /**
     * An empty page reports 0-0 rather than a range that runs backwards, which
     * is what "showing 1981-1960 of 340" is: arithmetic on an empty slice with no
     * guard for it.
     */
    public function test_an_empty_page_reports_no_item_range(): void
    {
        $page = new PaginatedResult([], total: 40, perPage: 20, currentPage: 99);

        $this->assertSame(0, $page->firstItem());
        $this->assertSame(0, $page->lastItem());
    }

    // ------------------------------------------------------------- navigation

    public function test_a_page_before_the_last_has_more(): void
    {
        $this->assertTrue(
            (new PaginatedResult([], total: 40, perPage: 20, currentPage: 1))->hasMorePages()
        );
    }

    public function test_the_last_page_has_no_more(): void
    {
        $this->assertFalse(
            (new PaginatedResult([], total: 40, perPage: 20, currentPage: 2))->hasMorePages()
        );
    }

    /**
     * A request past the end has no next page and yet is not a valid terminus
     * either. `hasMorePages()` alone cannot express the difference, which is why
     * the two are separate questions.
     */
    public function test_a_page_past_the_end_is_distinguishable_from_the_last_page(): void
    {
        $past = new PaginatedResult([], total: 40, perPage: 20, currentPage: 99);

        $this->assertTrue($past->isBeyondLastPage());
        $this->assertFalse($past->hasMorePages());
        $this->assertSame(99, $past->currentPage(), 'The page asked for is kept, not corrected.');
        $this->assertSame(2, $past->lastPage());
    }

    public function test_the_last_page_is_not_beyond_the_last_page(): void
    {
        $this->assertFalse(
            (new PaginatedResult([], total: 40, perPage: 20, currentPage: 2))->isBeyondLastPage()
        );
    }

    // ------------------------------------------------------------------ clamping

    /**
     * A `perPage` arriving from a query string is an unbounded integer, and a
     * zero reaches a LIMIT clause. Floored rather than refused, because the value
     * is usually computed rather than chosen.
     */
    public function test_per_page_is_floored_at_one(): void
    {
        $this->assertSame(1, (new PaginatedResult([], 0, 0, 1))->perPage());
        $this->assertSame(1, (new PaginatedResult([], 0, -5, 1))->perPage());
    }

    /**
     * `perPage=100000000` is a request that hands one client the whole table.
     */
    public function test_per_page_is_capped(): void
    {
        $this->assertSame(PaginatedResult::MAX_PER_PAGE, (new PaginatedResult([], 0, 999999, 1))->perPage());
    }

    public function test_the_cap_boundary_is_itself_allowed(): void
    {
        $this->assertSame(
            PaginatedResult::MAX_PER_PAGE,
            (new PaginatedResult([], 0, PaginatedResult::MAX_PER_PAGE, 1))->perPage()
        );
    }

    public function test_a_page_below_one_is_clamped_to_one(): void
    {
        $this->assertSame(1, (new PaginatedResult([], 0, 15, 0))->currentPage());
        $this->assertSame(1, (new PaginatedResult([], 0, 15, -5))->currentPage());
    }

    /**
     * Clamping happens before the arithmetic, so a negative page does not produce
     * a negative `firstItem()` — a negative OFFSET is what MySQL rejects outright
     * and SQLite silently reads as zero.
     */
    public function test_a_clamped_page_produces_a_positive_item_range(): void
    {
        $page = new PaginatedResult(range(1, 20), total: 40, perPage: 20, currentPage: -3);

        $this->assertSame(1, $page->firstItem());
        $this->assertSame(20, $page->lastItem());
    }

    /**
     * The one value that is refused rather than clamped.
     *
     * `perPage` and `currentPage` arrive from user input and have a correct
     * interpretation. A negative total is a bug in the COUNT that produced it, and
     * reading it as zero would report an empty result set instead of the fault.
     */
    public function test_a_negative_total_is_refused_rather_than_clamped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be negative');

        new PaginatedResult([], -1);
    }

    // -------------------------------------------------------------- withItems/map

    /**
     * The operation a mapping layer performs. Rebuilding a page by hand is how
     * `total` gets dropped and a decorated response reports its own slice size as
     * the grand total.
     */
    public function test_with_items_preserves_the_position_and_the_total(): void
    {
        $page = new PaginatedResult(range(1, 20), total: 340, perPage: 20, currentPage: 3);

        $decorated = $page->withItems(['a', 'b']);

        $this->assertSame(['a', 'b'], $decorated->items());
        $this->assertSame(340, $decorated->total(), 'The total is a property of the query, not the rows.');
        $this->assertSame(3, $decorated->currentPage());
        $this->assertSame(17, $decorated->lastPage());
        $this->assertSame(41, $decorated->firstItem());
    }

    public function test_with_items_returns_a_new_instance(): void
    {
        $page = new PaginatedResult([1, 2, 3], total: 3);

        $this->assertNotSame($page, $page->withItems([4, 5, 6]));
        $this->assertSame([1, 2, 3], $page->items(), 'The original must be untouched.');
    }

    public function test_map_transforms_every_item_and_preserves_the_page(): void
    {
        $page = new PaginatedResult([1, 2], total: 40, perPage: 2, currentPage: 2);

        $mapped = $page->map(static fn (int $n): int => $n * 10);

        $this->assertSame([10, 20], $mapped->items());
        $this->assertSame(40, $mapped->total());
        $this->assertSame(2, $mapped->currentPage());
    }

    /**
     * A mapper rendering alternating rows or row numbers needs to know where it
     * is. The offset is per page, not global, so a caller that wants a global
     * index adds `firstItem()`.
     */
    public function test_map_passes_the_offset_within_the_page(): void
    {
        $page = new PaginatedResult(['a', 'b', 'c'], total: 3);

        $mapped = $page->map(static fn (string $item, int $index): string => "{$index}:{$item}");

        $this->assertSame(['0:a', '1:b', '2:c'], $mapped->items());
    }

    // --------------------------------------------------------------------- items

    /**
     * A driver's keys are an artefact of how the rows were fetched. A JSON
     * payload built from them is an object where the client expects an array.
     */
    public function test_items_are_reindexed_as_a_list(): void
    {
        $page = new PaginatedResult(['x' => 'a', 'y' => 'b'], total: 2);

        $this->assertSame(['a', 'b'], $page->items());
        $this->assertSame([0, 1], array_keys($page->items()));
    }

    public function test_items_accepts_any_iterable(): void
    {
        $generator = (static function (): \Generator {
            yield 'ignored' => 1;
            yield 'also ignored' => 2;
        })();

        $this->assertSame([1, 2], (new PaginatedResult($generator, 2))->items());
    }

    // ---------------------------------------------------------------------- meta

    public function test_meta_describes_the_page_for_a_client(): void
    {
        $meta = (new PaginatedResult(range(1, 20), total: 340, perPage: 20, currentPage: 3))->meta();

        $this->assertSame(
            [
                'total' => 340,
                'per_page' => 20,
                'current_page' => 3,
                'last_page' => 17,
                'from' => 41,
                'to' => 60,
                'has_more_pages' => true,
            ],
            $meta
        );
    }

    /**
     * The meta block is a separate payload from the items because a flat array
     * would have to reserve names a resource field might legitimately want —
     * `total` and `page` are both plausible columns.
     */
    public function test_meta_is_empty_result_shaped_rather_than_absent(): void
    {
        $meta = PaginatedResult::empty()->meta();

        $this->assertSame(0, $meta['total']);
        $this->assertSame(0, $meta['from']);
        $this->assertSame(0, $meta['to']);
        $this->assertFalse($meta['has_more_pages']);
        $this->assertSame(1, $meta['last_page']);
    }

    // ----------------------------------------------------------- fromSlice/empty

    /**
     * The pairing a repository performs constantly: the driver returns this
     * page's rows and, separately, the count of all of them. A repository that
     * passes `count($rows)` as the total reports "1 of 1" for every page, which
     * is why the two numbers are separate parameters with separate names.
     */
    public function test_from_slice_keeps_the_slice_and_the_grand_total_apart(): void
    {
        $page = PaginatedResult::fromSlice(range(1, 20), total: 340, perPage: 20, currentPage: 1);

        $this->assertSame(20, $page->count());
        $this->assertSame(340, $page->total());
    }

    public function test_empty_keeps_the_position_it_was_asked_for(): void
    {
        $page = PaginatedResult::empty(perPage: 5, currentPage: 4);

        $this->assertTrue($page->isEmpty());
        $this->assertSame(5, $page->perPage());
        $this->assertSame(4, $page->currentPage());
    }
}
