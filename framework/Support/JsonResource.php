<?php
declare(strict_types=1);

namespace Framework\Support;

use JsonSerializable;
use Traversable;

/**
 * Maps domain data onto the shape an API client receives.
 *
 * ## The problem it exists to solve
 *
 * A controller that returns an entity directly has quietly made every one of that
 * entity's fields part of its public contract. Adding a `password_hash` column,
 * renaming `created` to `created_at`, or introducing an internal `legacy_id` all
 * change the response — and nothing warns you, because serializing an object has
 * no opinion about what *should* be in it.
 *
 * A resource is that opinion, written down in one place:
 *
 *     final class LeadResource extends JsonResource
 *     {
 *         protected function toArray(): array
 *         {
 *             return [
 *                 'id'         => $this->id,
 *                 'email'      => $this->email,
 *                 'created_at' => $this->created_at,
 *             ];
 *         }
 *     }
 *
 * The fields a client sees are now the fields someone chose, and the db column
 * that arrived yesterday is absent because nobody added it. This is the whole
 * point of the class; everything below is the mechanics of making it pleasant.
 *
 * ## Reading the wrapped data
 *
 * Inside `toArray()`, `$this->email` resolves in this order:
 *
 *  1. a public property of the wrapped object;
 *  2. a getter — `email()`, then `getEmail()`;
 *  3. a key on the wrapped array.
 *
 * Plain property syntax rather than `$this->data['email']` because the second
 * spelling is the reason API code ends up with a different vocabulary from the
 * rest of the project, and because it keeps a resource readable when the source
 * changes shape — swapping an array for an entity edits the resource's accessor
 * paths, not every line.
 *
 * A field that does not exist throws rather than resolving to null. A silently
 * null `email` in a response is a bug that reaches production and sits there;
 * `Undefined property` at the line that asked is a bug that reaches the
 * developer. {@see whenLoaded()} and {@see when()} are the ways to *say* a field
 * is conditional, so there is no reason to want the silent version.
 *
 * ## Collections
 *
 * {@see collection()} wraps many items and applies the same mapping to each, with
 * the envelope a client can iterate. Pagination is deliberately not here — see
 * {@see JsonResource::paginate()} for what is, and why the rest is not.
 */
abstract class JsonResource implements JsonSerializable
{
    /**
     * The value being mapped.
     */
    protected readonly mixed $resource;

    /**
     * Keys requested by the client via `?fields=`, or null for "everything".
     *
     * Null and an empty list mean different things, which is why this is not just
     * an array: null is "no field filter was requested", and an empty array is
     * "the client asked for a subset that turned out to be empty". Collapsing
     * them would make `?fields=` return the full object, which is a
     * sparse-fieldset request quietly answered with everything.
     *
     * @var list<string>|null
     */
    private ?array $requestedFields = null;

    /**
     * Extra key => value pairs merged into the output.
     *
     * @var array<string, mixed>
     */
    private array $additional = [];

    public function __construct(mixed $resource = null)
    {
        $this->resource = $resource;
    }

    // ------------------------------------------------------------ author's contract

    /**
     * The response shape: output key => value.
     *
     * @return array<string, mixed>
     */
    abstract protected function toArray(): array;

    // -------------------------------------------------------------- entry points

    /**
     * Wrap one item.
     */
    public static function make(mixed $resource): static
    {
        return new static($resource);
    }

    /**
     * Wrap many items as a collection resource.
     *
     * The return type is {@see JsonResource} rather than a static, because a
     * collection is a *different* resource class — one that maps many — and
     * pretending otherwise would mean `LeadResource::collection($leads)` handing
     * back something whose `toArray()` is called per item.
     *
     * `$resource` may be a plain array, a `Traversable`, or a WordPress query
     * result. It is normalized to a list of items here so that a resource never
     * has to care which of those it received; the keys of an associative array
     * are **dropped**, because a JSON array with non-sequential numeric keys
     * serializes to an *object* and a client's `for` loop stops working.
     */
    public static function collection(iterable $resource): JsonResourceCollection
    {
        return new JsonResourceCollection($resource, static::class);
    }

    // ------------------------------------------------------------- reading data

    /**
     * Resolve one output field.
     *
     * The accessor resolution described on the class docblock, and the reason a
     * resource reads like the domain object it maps. Kept `final` for the same
     * reason `whenLoaded()` and friends are: a subclass overriding access would
     * silently change what every `$this->x` in `toArray()` means, which is the
     * kind of change that is invisible at the definition site and everywhere else.
     *
     * @throws \LogicException When the field exists on none of the three sources.
     */
    final public function __get(string $name): mixed
    {
        if (is_object($this->resource)) {
            if (property_exists($this->resource, $name)) {
                return $this->resource->{$name};
            }

            // `email()` before `getEmail()`: the first is this framework's own
            // convention, and checking it first means a class carrying both does
            // not have the incidental one win.
            foreach ([$name, 'get' . ucfirst($name), 'is' . ucfirst($name)] as $method) {
                if (method_exists($this->resource, $method)) {
                    return $this->resource->{$method}();
                }
            }
        }

        if (is_array($this->resource) && array_key_exists($name, $this->resource)) {
            return $this->resource[$name];
        }

        throw new \LogicException(sprintf(
            'Field [%s] does not exist on the data wrapped by %s. Use when() or whenLoaded() for a '
            . 'field that is genuinely optional — an absent field is a mistake worth seeing, and a '
            . 'null that silently reaches a client never gets reported.',
            $name,
            static::class
        ));
    }

    /**
     * Whether a field resolves, without throwing.
     *
     * The escape hatch for a resource that legitimately maps a variable shape —
     * a union of two payloads, a value that may be a scalar or an object. It
     * cannot distinguish "absent" from "null", so it is not a substitute for
     * `when()` on a field the client should be told about conditionally.
     */
    final public function has(string $name): bool
    {
        try {
            $this->__get($name);

            return true;
        } catch (\LogicException) {
            return false;
        }
    }

    /**
     * The raw wrapped value.
     *
     * For the cases a resource genuinely needs the original — mapping a
     * collection's items, handing a nested resource its subject, testing whether
     * the thing is empty at all.
     */
    final public function resource(): mixed
    {
        return $this->resource;
    }

    // ------------------------------------------------------- conditional fields

    /**
     * A field that is present only when a condition holds.
     *
     *     'email' => $this->when($this->includePrivate, $this->email),
     *
     * Returning `null` for "omit" rather than an empty value is deliberate: a
     * `null` in the output means "this field exists and is null", which is a
     * different statement from "this field is not here". The distinction is the
     * only reason a client can tell a redacted field from an empty one.
     *
     * @param mixed $value The value to emit when the condition holds.
     * @param mixed $default Emitted instead when it does not. Defaults to omitting.
     *
     * @return $this|\Framework\Support\MissingValue
     */
    final protected function when(bool $condition, mixed $value, mixed $default = null): mixed
    {
        if ($condition) {
            return $value;
        }

        return $default === null ? new MissingValue() : $default;
    }

    /**
     * A field that is present only when the wrapped data actually has it.
     *
     *     'phone' => $this->whenLoaded('phone'),
     *
     * The difference from `when()` is that the test is about the *data* rather
     * than about a condition the author computed. `whenLoaded('phone')` emits the
     * field when the wrapped object exposes `phone` and omits it otherwise, which
     * is what makes an optional column or a lazily-attached relation expressible
     * without the resource knowing which it is.
     */
    final protected function whenLoaded(string $field, mixed $default = null): mixed
    {
        if (!$this->has($field)) {
            return $default === null ? new MissingValue() : $default;
        }

        return $this->__get($field);
    }

    // -------------------------------------------------------------- composition

    /**
     * Add a key to the output, from outside the resource.
     *
     * The seam for a controller that needs to attach something the resource has
     * no business knowing — a request id, a debug flag gated on capability, a
     * `meta` block. Merged **after** the mapped fields, so an added key cannot be
     * silently dropped by a field of the same name, and it wins rather than
     * losing — an explicit addition is the more deliberate statement.
     *
     * @param mixed $value
     */
    final public function with(string $key, mixed $value): static
    {
        $this->additional[$key] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    final public function withMany(array $values): static
    {
        foreach ($values as $key => $value) {
            $this->with((string) $key, $value);
        }

        return $this;
    }

    /**
     * Restrict the output to the fields the client asked for.
     *
     * ## What this is and is not
     *
     * It is a **filter over the fields the resource already decided to emit** —
     * a sparse fieldset, per the JSON:API convention. It is *not* a permission
     * mechanism, and that distinction is the one thing to get right here: a
     * client asking for `?fields=password_hash` is asking for a field the
     * resource never mapped, and the answer is "you cannot have it", not "here it
     * is". Filtering happens after `toArray()`, so a field is only ever
     * *narrowed*, never reached.
     *
     * `id` is always kept. A collection of objects without identifiers is not
     * usable by a client, and it is the one key every resource in this framework
     * is expected to carry.
     *
     * @param list<string> $fields
     */
    final public function only(array $fields): static
    {
        $this->requestedFields = array_values(array_unique(array_map('strval', $fields)));

        return $this;
    }

    /**
     * The fields the client asked for, or null when it asked for everything.
     *
     * @return list<string>|null
     */
    final public function requestedFields(): ?array
    {
        return $this->requestedFields;
    }

    // ---------------------------------------------------------------- resolution

    /**
     * The finished array, with conditionals resolved and field filtering applied.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $mapped = $this->prune($this->toArray());

        if ($this->requestedFields !== null) {
            $mapped = $this->filter($mapped);
        }

        // Additional keys go on last so they cannot be lost to a field of the
        // same name — an explicit with() is the more deliberate statement of the
        // two, and losing it would be silent.
        return array_replace($mapped, $this->additional);
    }

    /**
     * How the resource appears inside a JSON document.
     *
     * `json_encode()` on a resource calls this, which means a resource can be
     * returned from a route or nested inside another resource's output without
     * anything having to unwrap it by hand.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * A stable identifier for this resource, used by collections to key and to
     * look items up. Null when the data carries no `id`.
     *
     * Deliberately tolerant where `__get()` is not: a collection's job of
     * listing items should not fail because one of them was loaded without an
     * id, and `has()` gives the resource a defined answer without swallowing a
     * mistake in `toArray()`.
     */
    final public function id(): int|string|null
    {
        if (!$this->has('id')) {
            return null;
        }

        $id = $this->__get('id');

        return is_int($id) || is_string($id) ? $id : null;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Drop `MissingValue` markers and resolve anything nested.
     *
     * @param array<string, mixed> $mapped
     *
     * @return array<string, mixed>
     */
    private function prune(array $mapped): array
    {
        $resolved = [];

        foreach ($mapped as $key => $value) {
            if ($value instanceof MissingValue) {
                continue;
            }

            $resolved[$key] = $value instanceof JsonSerializable ? $value->jsonSerialize() : $value;
        }

        return $resolved;
    }

    /**
     * Keep only the requested keys, always retaining `id`.
     *
     * The comparison is on the *output* keys — after mapping — which is the whole
     * point: the client names the fields it was told about, and a name it was
     * never told about simply is not among them.
     *
     * @param array<string, mixed> $mapped
     *
     * @return array<string, mixed>
     */
    private function filter(array $mapped): array
    {
        $wanted = $this->requestedFields ?? [];

        $kept = [];

        foreach ($mapped as $key => $value) {
            if ($key === 'id' || in_array($key, $wanted, true)) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /**
     * Normalize an iterable into a list, for {@see JsonResourceCollection}.
     *
     * Public because the collection needs it and cannot reach a protected method
     * on another instance. Returns a list rather than preserving keys — see
     * `collection()` for why non-sequential keys are a bug in JSON.
     *
     * @return list<mixed>
     */
    final public static function normalizeItems(iterable $items): array
    {
        if (is_array($items)) {
            return array_values($items);
        }

        if ($items instanceof Traversable) {
            return array_values(iterator_to_array($items, false));
        }

        // Unreachable through the iterable type, but a WP_Query's `posts`
        // property or a wpdb result can arrive here through a loosely-typed
        // caller, and an empty collection beats a fatal.
        return [];
    }
}
