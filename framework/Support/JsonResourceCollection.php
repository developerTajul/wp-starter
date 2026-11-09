<?php
declare(strict_types=1);

namespace Framework\Support;

use JsonSerializable;

/**
 * Many items mapped through one {@see JsonResource}.
 *
 * Why this is a separate class rather than a mode of JsonResource
 *
 * A collection is not "a resource that happens to hold several things". The two
 * answer different questions - one maps a subject to its fields, the other maps a
 * *set* to a list - and the second needs operations the first has no meaning for:
 * a stable key for each item, a lookup by id, a count, an envelope that tells a
 * client how to iterate.
 *
 * Folding them together means every `JsonResource` carries `count()` and
 * `findById()` that make no sense on a single record, and the `toArray()` an
 * author writes is the wrong shape half the time.
 *
 * The envelope
 *
 * The output is an object with a `data` array rather than a bare array:
 *
 *     {"data": [{"id": 1, ...}, {"id": 2, ...}]}
 *
 * which is more bytes for a payload that could have been the array itself. The
 * reason is room to grow without breaking clients: pagination, a cursor, a
 * `meta` block, a set of included relations all belong *next to* the list, and a
 * response that is a bare array has nowhere to put them. Changing a bare array
 * into an object later is a breaking change for every client; starting as an
 * object is free.
 *
 * Why the keys are dropped
 *
 * {@see JsonResource::normalizeItems()} collapses the source to a list, so an
 * associative input - a `wpdb` result keyed by post id, a map of slug to record -
 * comes out sequential. This is not tidiness: `json_encode()` of an array with
 * non-sequential integer keys produces a JSON **object**, so a client's `for`
 * loop over `data` would fail against a payload that looks correct in a browser.
 * The keys are recoverable through `keyBy()` when a caller genuinely wants them.
 */
final class JsonResourceCollection implements JsonSerializable
{
    /**
     * The items, wrapped in their resources.
     *
     * Mutable rather than readonly because {@see only()} narrows the set in
     * place. Making it readonly would force that method to return a *new*
     * collection, which reads as an improvement right up until a controller
     * writes `$leads->only([...])->with(...)` and finds the filter silently
     * discarded - the fluent style this class is built for depends on the
     * mutators returning `$this`.
     *
     * @var list<mixed>
     */
    private array $items;

    /**
     * Extra keys merged alongside `data`.
     *
     * @var array<string, mixed>
     */
    private array $additional = [];

    /**
     * The sparse fieldset to apply to every item, or null for "everything".
     *
     * @var list<string>|null
     */
    private ?array $fields = null;

    /**
     * @param class-string<JsonResource> $resourceClass The resource that maps one item.
     */
    public function __construct(
        iterable $items,
        private readonly string $resourceClass
    ) {
        $this->items = JsonResource::normalizeItems($items);
    }

    /**
     * Wrap each item in the resource and map it.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $data = [];

        foreach ($this->items as $item) {
            $data[] = $this->wrap($item)->resolve();
        }

        return array_replace(['data' => $data], $this->additional);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * How many items the collection holds.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Whether there is nothing to map.
     *
     * Genuinely useful in a controller - an empty result set is often a 404 or a
     * different response rather than an empty list - and cheap enough that
     * leaving it out would just mean every caller doing `count($c->resolve()['data'])`,
     * which maps the whole collection to answer a question about its length.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Re-map the collection, keyed by each item's `id`.
     *
     * The opt-in recovery of what {@see JsonResource::normalizeItems()} dropped,
     * for a client that wants a lookup table rather than a list - a select box,
     * a diffing client. Items with no id are skipped rather than keyed by an
     * empty string, which would silently collapse several of them into one.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function keyById(): array
    {
        $keyed = [];

        foreach ($this->items as $item) {
            $resource = $this->wrap($item);

            $id = $resource->id();

            if ($id !== null) {
                $keyed[$id] = $resource->resolve();
            }
        }

        return $keyed;
    }

    /**
     * The mapped collection as a plain list, without the envelope.
     *
     * For embedding one collection inside another resource's fields, where the
     * `data` wrapper would be a second envelope in the middle of a document.
     *
     * @return list<array<string, mixed>>
     */
    public function toList(): array
    {
        $list = [];

        foreach ($this->items as $item) {
            $list[] = $this->wrap($item)->resolve();
        }

        return $list;
    }

    /**
     * Add a key alongside `data`, outside the resource.
     *
     * Where pagination metadata goes when a project adds it:
     * `->with('meta', ['total' => 240, 'page' => 2])`. Deliberately a seam rather
     * than a built-in - this framework has no paginator, and inventing an
     * envelope shape for one nobody has written would be a guess that clients
     * then depend on.
     *
     * @param mixed $value
     */
    public function with(string $key, mixed $value): self
    {
        $this->additional[$key] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function withMany(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->with((string) $key, $value);
        }

        return $this;
    }

    /**
     * @return list<mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Apply a sparse fieldset to every item.
     *
     * The filter is held here and handed to each item as it is mapped, rather
     * than applied by mapping the collection once to produce filtered resources
     * and mapping it again to resolve them. That version wrapped every item
     * eagerly, which meant `only()` on a collection that was never sent still
     * cost a full pass - and, worse, made the wrapping happen at a different
     * moment from every other read, so a resource that throws on an absent field
     * threw at the `only()` line instead of at the response.
     *
     * @param list<string> $fields
     */
    public function only(array $fields): self
    {
        $this->fields = array_values(array_unique(array_map('strval', $fields)));

        return $this;
    }

    /**
     * Wrap one item in the collection's resource class, with this collection's
     * field filter applied.
     *
     * An item that is *already* a resource instance is passed through - but still
     * receives the filter, because a caller handing in prepared resources still
     * asked this collection for a sparse fieldset. Re-wrapping them instead would
     * try to read fields off a resource *through* the resource, which resolves to
     * nonsense rather than an error.
     */
    private function wrap(mixed $item): JsonResource
    {
        $resource = $item instanceof JsonResource
            ? $item
            : new ($this->resourceClass)($item);

        return $this->fields === null ? $resource : $resource->only($this->fields);
    }
}
