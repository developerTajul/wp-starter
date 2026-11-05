<?php
declare(strict_types=1);

namespace Tests\Unit\Support;

use Framework\Support\JsonResource;
use Framework\Support\JsonResourceCollection;
use Framework\Support\MissingValue;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\Fixtures\AccessorLeadResource;
use Tests\Unit\Support\Fixtures\AnonymousResource;
use Tests\Unit\Support\Fixtures\BrokenResource;
use Tests\Unit\Support\Fixtures\CompanyResource;
use Tests\Unit\Support\Fixtures\DetailedLeadResource;
use Tests\Unit\Support\Fixtures\Lead;
use Tests\Unit\Support\Fixtures\LeadResource;
use Tests\Unit\Support\Fixtures\NestingLeadResource;
use Tests\Unit\Support\Fixtures\RefLeadResource;
use Tests\Unit\Support\Fixtures\ThrowingResource;

/**
 * The API mapping layer: field resolution, conditional fields, and the collection.
 *
 * ## What is worth pinning
 *
 * Mapping an array to another array is not interesting. The four things that are:
 *
 *  - **A field the resource never mapped is not in the output.** This is the
 *    entire justification for the class — a resource exists so that adding a
 *    database column does not silently change a public response. If that is not
 *    true the class is decoration.
 *  - **An absent field throws rather than emitting null.** A silently-null field
 *    reaches production and is never reported; an exception reaches the developer
 *    at the line that asked.
 *  - **Sparse fieldsets narrow and cannot widen.** `?fields=internalRef` must not
 *    reach a field the resource declined to map.
 *  - **A collection's JSON is an array, not an object.** Non-sequential keys
 *    encode as `{}` and a client's `for` loop stops working against a payload
 *    that looks correct.
 */
final class JsonResourceTest extends TestCase
{
    // ------------------------------------------------------------- the mapping

    public function test_a_resource_maps_the_fields_it_declares(): void
    {
        $resource = LeadResource::make(['id' => 7, 'email' => 'a@b.test', 'name' => 'Ada']);

        $this->assertSame(
            ['id' => 7, 'email' => 'a@b.test', 'name' => 'Ada'],
            $resource->resolve()
        );
    }

    /**
     * The point of the whole layer. `Lead::$internalRef` is public on the entity
     * and absent from the response, because the resource lists fields explicitly.
     * A resource that serialized its subject would leak it.
     */
    public function test_a_field_the_resource_does_not_map_is_not_in_the_output(): void
    {
        $lead = new Lead(7, 'a@b.test', 'INTERNAL-SECRET');

        $resolved = RefLeadResource::make($lead)->resolve();

        $this->assertArrayNotHasKey('internalRef', $resolved);
        $this->assertStringNotContainsString('INTERNAL-SECRET', (string) json_encode($resolved));
    }

    public function test_a_resource_reads_an_entity_by_property(): void
    {
        $resolved = RefLeadResource::make(new Lead(7, 'a@b.test', 'ref'))->resolve();

        $this->assertSame(7, $resolved['id']);
        $this->assertSame('a@b.test', $resolved['email']);
    }

    /**
     * Accessor resolution, both spellings. `label` reaches `Lead::label()` and
     * `active` reaches `Lead::isActive()` — the `is` prefix is what makes a
     * boolean read naturally in the resource without the entity renaming itself.
     */
    public function test_a_resource_reads_an_entity_through_accessors(): void
    {
        $resolved = AccessorLeadResource::make(new Lead(7, 'a@b.test', 'ref'))->resolve();

        $this->assertSame('Lead #7', $resolved['label']);
        $this->assertTrue($resolved['active']);
    }

    /**
     * A property wins over a method of the same name. The alternative — method
     * first — means adding a getter to an entity silently changes the value a
     * response carries, which is a change nothing would report.
     */
    public function test_a_property_wins_over_an_accessor_of_the_same_name(): void
    {
        $subject = new class {
            public string $value = 'property';

            public function value(): string
            {
                return 'method';
            }
        };

        $resource = new class ($subject) extends JsonResource {
            protected function toArray(): array
            {
                return ['value' => $this->value];
            }
        };

        $this->assertSame('property', $resource->resolve()['value']);
    }

    public function test_an_absent_field_throws_rather_than_emitting_null(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not exist');

        BrokenResource::make(['id' => 1])->resolve();
    }

    /**
     * The message has to name the field, or the reader is told only that
     * *something* in the resource is wrong. `emial` in the message is what turns
     * a stack trace into a one-second fix.
     */
    public function test_the_absent_field_error_names_the_field(): void
    {
        try {
            BrokenResource::make(['id' => 1])->resolve();
            $this->fail('The resource should have thrown.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('emial', $e->getMessage());
            $this->assertStringContainsString('BrokenResource', $e->getMessage());
        }
    }

    /**
     * A real failure inside `toArray()` must keep propagating as itself. Catching
     * around it to produce a friendlier absent-field error would hide a genuine
     * bug behind a message about a missing field.
     */
    public function test_a_failure_inside_the_mapping_propagates_unchanged(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mapping blew up');

        ThrowingResource::make([])->resolve();
    }

    public function test_has_reports_a_field_without_throwing(): void
    {
        $resource = LeadResource::make(['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada']);

        $this->assertTrue($resource->has('email'));
        $this->assertFalse($resource->has('emial'));
    }

    public function test_the_raw_resource_is_reachable(): void
    {
        $lead = new Lead(7, 'a@b.test', 'ref');

        $this->assertSame($lead, LeadResource::make($lead)->resource());
    }

    // --------------------------------------------------------- conditional fields

    /**
     * `whenLoaded()` tests the *data*: the field appears because the entity has
     * it, with no flag for the caller to set.
     */
    public function test_when_loaded_emits_a_field_the_data_carries(): void
    {
        $resolved = DetailedLeadResource::make(new Lead(7, 'a@b.test', 'ref', '555-0100'))->resolve();

        $this->assertSame('555-0100', $resolved['phone']);
    }

    /**
     * The field is omitted when the wrapped data has no such property at all.
     *
     * An anonymous object rather than a {@see Lead} with `phone` unset, because
     * there is no such thing: `Lead` *declares* `$phone`, so `property_exists()`
     * is true for every instance whether or not a value was supplied, and the
     * framework resolves it to null. The two situations are genuinely different
     * and this is the one `whenLoaded()` is for — a subject whose shape does not
     * include the field.
     *
     * That distinction is worth stating plainly, because it is the trap: writing
     * `new Lead(7, 'a@b.test', 'ref')` here and asserting on `phone` does not
     * test omission, it tests that an optional column holds null — and the
     * assertion passes or fails for a reason unrelated to `whenLoaded()`.
     */
    public function test_when_loaded_omits_a_field_the_data_lacks(): void
    {
        $subject = new class {
            public int $id = 7;

            public string $email = 'a@b.test';
        };

        $resolved = DetailedLeadResource::make($subject)->resolve();

        $this->assertArrayNotHasKey('phone', $resolved);
    }

    /**
     * A declared-but-null property *is* emitted, holding null.
     *
     * The companion to the test above, and the reason the two are separate: this
     * is the framework reporting "there is no phone number", which a client has
     * to be able to tell apart from "this response does not include phone".
     */
    public function test_a_declared_property_holding_null_is_emitted_as_null(): void
    {
        $resolved = DetailedLeadResource::make(new Lead(7, 'a@b.test', 'ref'))->resolve();

        $this->assertArrayHasKey('phone', $resolved);
        $this->assertNull($resolved['phone']);
    }

    /**
     * And `when()` tests a condition the author computed, independently of the
     * data. Both are absent here, which is what shows they are separate mechanisms
     * rather than one.
     */
    public function test_when_omits_while_its_condition_is_false(): void
    {
        $resource = DetailedLeadResource::make(new Lead(7, 'a@b.test', 'ref'));

        $this->assertArrayNotHasKey('notes', $resource->resolve());
    }

    public function test_when_emits_once_its_condition_holds(): void
    {
        $resource = DetailedLeadResource::make(new Lead(7, 'a@b.test', 'ref'));
        $resource->includeNotes = true;

        $this->assertSame('Internal note text', $resource->resolve()['notes']);
    }

    /**
     * An omitted field is *absent*, not null. This is the distinction the sentinel
     * exists for: a client has to be able to tell "redacted" from "empty", and a
     * null for both makes them the same answer.
     */
    public function test_an_omitted_field_is_absent_and_not_null(): void
    {
        $resolved = DetailedLeadResource::make(new Lead(7, 'a@b.test', 'ref'))->resolve();

        $this->assertArrayNotHasKey('notes', $resolved);
        $this->assertCount(3, $resolved);
    }

    /**
     * A legitimate null survives. `whenLoaded()` with a field that exists and *is*
     * null emits the key — so a resource can report "this lead has no phone" as
     * opposed to "this resource does not expose phone".
     */
    public function test_a_present_field_whose_value_is_null_is_still_emitted(): void
    {
        $resource = new class (['id' => 1, 'phone' => null]) extends JsonResource {
            protected function toArray(): array
            {
                return ['id' => $this->id, 'phone' => $this->whenLoaded('phone')];
            }
        };

        $resolved = $resource->resolve();

        $this->assertArrayHasKey('phone', $resolved);
        $this->assertNull($resolved['phone']);
    }

    /**
     * **The trap.** `when()` is an ordinary function call, so PHP evaluates every
     * argument before it runs — `when($flag, $this->notes)` reads `$this->notes`
     * whether or not the flag holds. On a row that lacks `notes` that read throws,
     * which means the condition that was supposed to make the row safe is what
     * breaks it.
     *
     * The first assertion is the failing shape, written down so the trap is
     * visible rather than described. The second is the fix.
     */
    public function test_when_does_not_defer_its_value_argument(): void
    {
        $broken = new class (['id' => 1]) extends JsonResource {
            public bool $flag = false;

            protected function toArray(): array
            {
                return ['id' => $this->id, 'notes' => $this->when($this->flag, $this->notes)];
            }
        };

        try {
            $broken->resolve();
            $this->fail('Reading an absent field inside when() should have thrown — the trap is real.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('notes', $e->getMessage());
        }
    }

    /**
     * The fix for the trap above: `whenLoaded()` defers the read, so the outer
     * `when()` receives a value that was never resolved. This is the shape for "a
     * field that is both optional and permissioned" — the row does not have to
     * carry the key for the mapping to succeed.
     */
    public function test_when_around_when_loaded_tolerates_an_absent_field(): void
    {
        $resource = new class (['id' => 1]) extends JsonResource {
            public bool $flag = false;

            protected function toArray(): array
            {
                return ['id' => $this->id, 'notes' => $this->when($this->flag, $this->whenLoaded('notes'))];
            }
        };

        $this->assertSame(['id' => 1], $resource->resolve());
    }

    /**
     * And the same shape emits the value once the flag holds and the data has it.
     * Without this the test above would pass for a resource that never emits
     * `notes` at all.
     */
    public function test_when_around_when_loaded_emits_once_both_hold(): void
    {
        $resource = new class (['id' => 1, 'notes' => 'internal']) extends JsonResource {
            public bool $flag = false;

            protected function toArray(): array
            {
                return ['id' => $this->id, 'notes' => $this->when($this->flag, $this->whenLoaded('notes'))];
            }
        };

        $resource->flag = true;

        $this->assertSame('internal', $resource->resolve()['notes']);
    }

    /**
     * A default can be given instead of omission, for a field where absence would
     * be worse for the client than a placeholder.
     */
    public function test_when_can_emit_a_default_instead_of_omitting(): void
    {
        $resource = new class (['id' => 1]) extends JsonResource {
            protected function toArray(): array
            {
                return ['id' => $this->id, 'role' => $this->when(false, 'admin', 'member')];
            }
        };

        $this->assertSame('member', $resource->resolve()['role']);
    }

    // -------------------------------------------------------------- composition

    public function test_a_nested_resource_is_resolved(): void
    {
        $lead = [
            'id' => 1,
            'email' => 'a@b.test',
            'company' => ['name' => 'Acme', 'city' => 'Lagos'],
        ];

        $this->assertSame(
            ['name' => 'Acme', 'city' => 'Lagos'],
            NestingLeadResource::make($lead)->resolve()['company']
        );
    }

    /**
     * `json_encode()` on a resource has to produce the mapped object, because a
     * resource nested inside another one is never unwrapped by hand — the encoder
     * is what resolves it.
     */
    public function test_a_resource_is_json_serializable(): void
    {
        $resource = LeadResource::make(['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada']);

        $this->assertSame(
            '{"id":1,"email":"a@b.test","name":"Ada"}',
            json_encode($resource)
        );
    }

    public function test_a_nested_resource_survives_json_encoding(): void
    {
        $lead = ['id' => 1, 'email' => 'a@b.test', 'company' => ['name' => 'Acme', 'city' => 'Lagos']];

        $this->assertSame(
            '{"id":1,"email":"a@b.test","company":{"name":"Acme","city":"Lagos"}}',
            json_encode(NestingLeadResource::make($lead))
        );
    }

    public function test_with_adds_a_key_outside_the_mapping(): void
    {
        $resource = LeadResource::make(['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'])
            ->with('request_id', 'abc-123');

        $this->assertSame('abc-123', $resource->resolve()['request_id']);
    }

    /**
     * An added key wins over a mapped field of the same name. The `with()` is the
     * more deliberate of the two statements, and losing it silently would be the
     * version of this that is hard to notice.
     */
    public function test_an_added_key_wins_over_a_mapped_field_of_the_same_name(): void
    {
        $resource = LeadResource::make(['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'])
            ->with('name', 'Overridden');

        $this->assertSame('Overridden', $resource->resolve()['name']);
    }

    public function test_with_many_adds_several_keys(): void
    {
        $resource = LeadResource::make(['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'])
            ->withMany(['a' => 1, 'b' => 2]);

        $this->assertSame(1, $resource->resolve()['a']);
        $this->assertSame(2, $resource->resolve()['b']);
    }

    // ------------------------------------------------------------ field filtering

    public function test_only_narrows_the_output(): void
    {
        $resource = LeadResource::make(['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'])
            ->only(['email']);

        $this->assertSame(['id' => 1, 'email' => 'a@b.test'], $resource->resolve());
    }

    /**
     * `id` is retained even though it was not asked for. A list of objects without
     * identifiers is not usable, and every resource in this framework carries one.
     */
    public function test_only_always_keeps_the_id(): void
    {
        $resource = LeadResource::make(['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'])
            ->only(['name']);

        $this->assertArrayHasKey('id', $resource->resolve());
    }

    /**
     * **The security-relevant one.** A field the resource never mapped cannot be
     * reached by asking for it. This is the difference between a sparse fieldset
     * and a permission system, and it is the behaviour that must not regress: if
     * `only()` could name any field, then `?fields=internalRef` would be an
     * exfiltration primitive on every endpoint.
     */
    public function test_only_cannot_reach_a_field_the_resource_did_not_map(): void
    {
        $resource = RefLeadResource::make(new Lead(7, 'a@b.test', 'INTERNAL-SECRET'))
            ->only(['internalRef', 'id']);

        $resolved = $resource->resolve();

        $this->assertArrayNotHasKey('internalRef', $resolved);
        $this->assertStringNotContainsString('INTERNAL-SECRET', (string) json_encode($resolved));
    }

    public function test_requested_fields_are_reported_for_an_outer_layer(): void
    {
        $resource = LeadResource::make(['id' => 1])->only(['email']);

        $this->assertSame(['email'], $resource->requestedFields());
        $this->assertNull(LeadResource::make(['id' => 1])->requestedFields());
    }

    // -------------------------------------------------------------------- identity

    public function test_id_is_reported_when_present(): void
    {
        $this->assertSame(7, LeadResource::make(['id' => 7])->id());
    }

    /**
     * A string id is legal — WordPress terms and many external systems use one —
     * and must not be coerced to an int, which would change the value a client
     * gets back.
     */
    public function test_a_string_id_is_reported_unchanged(): void
    {
        $this->assertSame('abc-1', LeadResource::make(['id' => 'abc-1'])->id());
    }

    public function test_id_is_null_when_the_data_has_none(): void
    {
        $this->assertNull(AnonymousResource::make(['value' => 'x'])->id());
    }

    // ----------------------------------------------------------------- collections

    /**
     * The envelope. A bare array has nowhere to put pagination or meta later, and
     * changing an array into an object is a breaking change for every client.
     */
    public function test_a_collection_wraps_its_items_in_a_data_key(): void
    {
        $collection = LeadResource::collection([
            ['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'],
            ['id' => 2, 'email' => 'c@d.test', 'name' => 'Bob'],
        ]);

        $resolved = $collection->resolve();

        $this->assertArrayHasKey('data', $resolved);
        $this->assertCount(2, $resolved['data']);
        $this->assertSame('Ada', $resolved['data'][0]['name']);
        $this->assertSame('Bob', $resolved['data'][1]['name']);
    }

    /**
     * **The one that breaks clients while looking correct.** An associative input
     * — a `wpdb` result keyed by post id — would encode as a JSON *object* under
     * `data`, and a client's `for` loop would fail against it. The keys are
     * dropped so `data` is always an array.
     */
    public function test_a_collection_with_non_sequential_keys_still_encodes_as_an_array(): void
    {
        $collection = LeadResource::collection([
            42 => ['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'],
            99 => ['id' => 2, 'email' => 'c@d.test', 'name' => 'Bob'],
        ]);

        $json = json_encode($collection);

        $this->assertStringStartsWith('{"data":[', $json);
        $this->assertStringNotContainsString('"42"', $json);
    }

    public function test_a_collection_accepts_a_traversable(): void
    {
        $items = new \ArrayIterator([
            ['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'],
        ]);

        $this->assertCount(1, LeadResource::collection($items)->resolve()['data']);
    }

    public function test_an_empty_collection_resolves_to_an_empty_data_list(): void
    {
        $resolved = LeadResource::collection([])->resolve();

        $this->assertSame([], $resolved['data']);
        $this->assertSame('{"data":[]}', json_encode(LeadResource::collection([])));
    }

    public function test_a_collection_counts_and_reports_emptiness(): void
    {
        $collection = LeadResource::collection([['id' => 1, 'email' => 'a@b.test', 'name' => 'A']]);

        $this->assertSame(1, $collection->count());
        $this->assertFalse($collection->isEmpty());
        $this->assertTrue(LeadResource::collection([])->isEmpty());
    }

    public function test_key_by_id_indexes_the_items(): void
    {
        $keyed = LeadResource::collection([
            ['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'],
            ['id' => 2, 'email' => 'c@d.test', 'name' => 'Bob'],
        ])->keyById();

        $this->assertSame(['1', '2'], array_map('strval', array_keys($keyed)));
        $this->assertSame('Ada', $keyed[1]['name']);
    }

    /**
     * An item with no id is skipped rather than keyed by an empty string, which
     * would silently collapse every such item into one entry.
     */
    public function test_key_by_id_skips_items_without_one(): void
    {
        $keyed = AnonymousResource::collection([
            ['value' => 'a'],
            ['value' => 'b'],
        ])->keyById();

        $this->assertSame([], $keyed);
    }

    public function test_to_list_drops_the_envelope(): void
    {
        $list = LeadResource::collection([
            ['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'],
        ])->toList();

        $this->assertSame([['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada']], $list);
    }

    public function test_a_collection_accepts_additional_keys(): void
    {
        $collection = LeadResource::collection([])->with('meta', ['total' => 240]);

        $this->assertSame(['total' => 240], $collection->resolve()['meta']);
    }

    /**
     * The sparse fieldset reaching a collection has to apply to every item, or a
     * controller would have to write the loop itself.
     */
    public function test_only_applies_to_every_item_in_a_collection(): void
    {
        $collection = LeadResource::collection([
            ['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada'],
            ['id' => 2, 'email' => 'c@d.test', 'name' => 'Bob'],
        ])->only(['email']);

        $resolved = $collection->resolve()['data'];

        $this->assertSame(['id' => 1, 'email' => 'a@b.test'], $resolved[0]);
        $this->assertSame(['id' => 2, 'email' => 'c@d.test'], $resolved[1]);
    }

    /**
     * An item that is already a resource is mapped through, not re-wrapped.
     * Re-wrapping would read fields off a resource *through* a resource, which
     * resolves to nonsense rather than an error — the worst kind of bug.
     */
    public function test_a_collection_accepts_prebuilt_resources(): void
    {
        $collection = LeadResource::collection([
            LeadResource::make(['id' => 1, 'email' => 'a@b.test', 'name' => 'Ada']),
        ]);

        $this->assertSame('Ada', $collection->resolve()['data'][0]['name']);
    }

    public function test_an_empty_collection_encodes_without_error(): void
    {
        $this->assertSame('{"data":[]}', json_encode(AnonymousResource::collection([])));
    }

    /**
     * A collection is its own type rather than a mode of JsonResource, so that
     * `collection()` can return something with `count()` and `keyById()` that
     * would be meaningless on a single record.
     */
    public function test_a_collection_is_its_own_type(): void
    {
        $collection = LeadResource::collection([]);

        $this->assertInstanceOf(JsonResourceCollection::class, $collection);
        $this->assertNotInstanceOf(JsonResource::class, $collection);
    }

    /**
     * The sentinel is a class, so no value a resource author can produce is
     * mistaken for it. A string sentinel would be one careless `toArray()` away
     * from being emitted as a real field value.
     */
    public function test_the_missing_marker_cannot_collide_with_a_real_value(): void
    {
        $marker = new MissingValue();

        $this->assertNotInstanceOf(MissingValue::class, 'anything');
        $this->assertNotInstanceOf(MissingValue::class, null);
        $this->assertNotInstanceOf(MissingValue::class, 0);
        $this->assertNotInstanceOf(MissingValue::class, ['data' => 'x']);
    }

    // ------------------------------------------------------------------ ergonomics

    public function test_make_returns_the_declaring_resource_type(): void
    {
        $this->assertInstanceOf(CompanyResource::class, CompanyResource::make(['name' => 'Acme', 'city' => 'Lagos']));
    }

    /**
     * The scenario the whole layer exists for, end to end: an entity gains an
     * internal field and the response is unchanged, because the resource never
     * mentioned it.
     */
    public function test_adding_an_entity_field_does_not_change_the_response(): void
    {
        $before = RefLeadResource::make(new Lead(7, 'a@b.test', 'ref'))->resolve();

        $withExtra = new class (7, 'a@b.test', 'ref') {
            public function __construct(
                public readonly int $id,
                public readonly string $email,
                public readonly string $internalRef,
                public readonly string $newColumnNobodyMapped = 'surprise',
            ) {
            }
        };

        $after = RefLeadResource::make($withExtra)->resolve();

        $this->assertSame($before, $after);
    }
}
