<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Lead;

use Core\Lead\Domain\Entities\LeadEntity;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * The lead entity's rules, asserted with no database and no WordPress.
 *
 * ## Why this file can exist at all
 *
 * `Core\Lead\Domain` imports nothing but `Core\` classes and PHP builtins — no
 * `$wpdb`, no `wp_cache_*`, no framework. That is the property the whole
 * three-zone arrangement exists to produce, and this test is what it buys: every
 * business rule below is verified in microseconds, with no stub layer loaded and
 * no fixtures, so a failure here is a rule that is wrong rather than a harness
 * that is.
 *
 * ## The rules worth naming
 *
 * Two are non-obvious enough that they are the reason the class is not trivial:
 *
 *  - **A lead needs an email *or* a phone**, not one of them specifically. A
 *    form delivers an address and a webhook a number, so a rule written against
 *    either field alone rejects a real case, and a rule written against neither
 *    accepts a row nobody can contact.
 *  - **A blank string is absent, not a value.** The database stores `''` and
 *    `NULL` differently, and a form submits an untouched field as `''` — so
 *    without normalisation "no phone number" is recorded two ways depending on
 *    which route it arrived by, and every consumer has to test both.
 */
final class LeadEntityTest extends TestCase
{
    private const NOW = '2026-11-08 12:00:00';

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
    }

    /**
     * A lead with both contact details, which is the ordinary case.
     */
    private function validLead(): LeadEntity
    {
        $result = LeadEntity::create(
            'Ada Lovelace',
            'ada@example.test',
            '555-0100',
            'form',
            'Analytical Engines Ltd',
            null,
            $this->now(),
        );

        $this->assertTrue($result->isOk(), $result->isOk() ? '' : $result->message());

        /** @var LeadEntity $lead */
        $lead = $result->value();

        return $lead;
    }

    // ------------------------------------------------------------------ creation

    public function test_a_lead_with_an_email_and_a_phone_is_created(): void
    {
        $lead = $this->validLead();

        $this->assertSame('Ada Lovelace', $lead->name);
        $this->assertSame('ada@example.test', $lead->email);
        $this->assertSame('555-0100', $lead->phone);
        $this->assertSame(LeadEntity::STATUS_NEW, $lead->status);
        $this->assertSame('form', $lead->source);
        $this->assertNull($lead->assignedTo, 'A new lead has no owner until somebody claims it.');
    }

    /**
     * A lead is created without an id, because storage owns that value.
     */
    public function test_a_created_lead_has_no_id_and_does_not_exist_yet(): void
    {
        $lead = $this->validLead();

        $this->assertNull($lead->id);
        $this->assertFalse($lead->exists());
    }

    /**
     * The pair rule: an email alone is enough.
     *
     * The form case. A validator that required a phone number would reject every
     * submission from a form that does not ask for one.
     */
    public function test_a_lead_with_only_an_email_is_created(): void
    {
        $result = LeadEntity::create('Ada', 'ada@example.test', null, 'form', now: $this->now());

        $this->assertTrue($result->isOk());
        /** @var LeadEntity $lead */
        $lead = $result->value();
        $this->assertNull($lead->phone);
    }

    /**
     * The pair rule: a phone alone is enough.
     *
     * The webhook case, and the one a validator written around the form would
     * reject.
     */
    public function test_a_lead_with_only_a_phone_is_created(): void
    {
        $result = LeadEntity::create('Ada', null, '555-0100', 'webhook', now: $this->now());

        $this->assertTrue($result->isOk());
        /** @var LeadEntity $lead */
        $lead = $result->value();
        $this->assertNull($lead->email);
    }

    /**
     * The pair rule's other half: neither is refused.
     *
     * The failure must be reported against **both** fields, because a form
     * rendering both should show the problem beside each — and a message attached
     * only to `email` would be invisible to a user who filled in neither.
     */
    public function test_a_lead_with_neither_contact_detail_is_refused_against_both_fields(): void
    {
        $result = LeadEntity::create('Ada', null, null, 'form', now: $this->now());

        $this->assertTrue($result->isFailure());

        $errors = $result->errors();

        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('phone', $errors);
        $this->assertSame(422, $result->status());
    }

    /**
     * A blank optional field is read as absent, not as an empty value.
     *
     * The case that decides whether "no phone number" is recorded one way or two.
     * Asserted through the pair rule: a blank phone and no email must fail, which
     * can only happen if the blank was normalised to null first.
     */
    public function test_a_blank_optional_field_is_treated_as_absent(): void
    {
        $result = LeadEntity::create('Ada', null, '   ', 'form', now: $this->now());

        $this->assertTrue(
            $result->isFailure(),
            'A whitespace-only phone number is not a contact detail, and with no email the lead is unreachable.'
        );
        $this->assertArrayHasKey('phone', $result->errors());
    }

    /**
     * A whitespace-only name is refused, and the length is judged after trimming.
     */
    public function test_a_blank_name_is_refused(): void
    {
        $result = LeadEntity::create('   ', 'ada@example.test', null, 'form', now: $this->now());

        $this->assertTrue($result->isFailure());
        $this->assertArrayHasKey('name', $result->errors());
    }

    /**
     * An over-long name is refused with the count, so the user can act on it.
     */
    public function test_an_over_long_name_is_refused(): void
    {
        $result = LeadEntity::create(
            str_repeat('a', LeadEntity::MAX_NAME_LENGTH + 1),
            'ada@example.test',
            null,
            'form',
            now: $this->now(),
        );

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('at most 255', $result->errors()['name'][0]);
    }

    /**
     * A malformed email is refused rather than stored.
     *
     * The double `@` is the typo that matters — it is the one a person types, and
     * it is the one that makes the address unusable for the only thing an address
     * is for.
     */
    public function test_a_malformed_email_is_refused(): void
    {
        $result = LeadEntity::create('Ada', 'ada@@example.test', null, 'form', now: $this->now());

        $this->assertTrue($result->isFailure());
        $this->assertArrayHasKey('email', $result->errors());
    }

    /**
     * A missing source is refused, not defaulted.
     *
     * Source is what every attribution report groups by. A default bucket would
     * swallow both the leads whose source was genuinely unrecorded and the ones
     * where a caller forgot to send the field, and the report would look complete.
     */
    public function test_a_lead_with_no_source_is_refused(): void
    {
        $result = LeadEntity::create('Ada', 'ada@example.test', null, '  ', now: $this->now());

        $this->assertTrue($result->isFailure());
        $this->assertArrayHasKey('source', $result->errors());
    }

    /**
     * An assignment to a non-existent user is refused; null is fine.
     *
     * The distinction the nullable column makes: unassigned is a normal state,
     * and `0` is a computed id that matches no user — so every ownership check
     * against it would refuse with nothing to explain why.
     */
    public function test_an_assignment_to_user_zero_is_refused(): void
    {
        $result = LeadEntity::create('Ada', 'ada@example.test', null, 'form', null, 0, $this->now());

        $this->assertTrue($result->isFailure());
        $this->assertArrayHasKey('assigned_to', $result->errors());
    }

    /**
     * Every problem is reported at once, not one per submission.
     *
     * A validator that stopped at the first failure would make a user fix their
     * input one round trip at a time.
     */
    public function test_every_problem_is_reported_together(): void
    {
        $result = LeadEntity::create('', 'not-an-email', null, '', now: $this->now());

        $this->assertTrue($result->isFailure());

        $errors = $result->errors();

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('source', $errors);
    }

    // ------------------------------------------------------------- reconstitution

    /**
     * A row read back from storage is trusted, not re-validated.
     */
    public function test_reconstitute_builds_an_entity_that_exists(): void
    {
        $lead = LeadEntity::reconstitute(
            7,
            'Grace Hopper',
            'grace@example.test',
            null,
            null,
            'contacted',
            'import',
            null,
            $this->now(),
        );

        $this->assertSame(7, $lead->id);
        $this->assertTrue($lead->exists());
        $this->assertSame('contacted', $lead->status);
    }

    /**
     * `updated_at` defaults to `created_at` for a row that has never been edited.
     */
    public function test_reconstitute_defaults_the_update_time_to_the_creation_time(): void
    {
        $lead = LeadEntity::reconstitute(
            7, 'Grace', 'grace@example.test', null, null, 'new', 'form', null, $this->now()
        );

        $this->assertEquals($lead->createdAt, $lead->updatedAt);
    }

    /**
     * A stored row with no way to contact anyone is a fault, and says so.
     *
     * This is the one check `reconstitute()` repeats from `create()`, and it is
     * an integrity assertion rather than validation: every consumer of a
     * `LeadEntity` may assume a contact detail is present, and handing out an
     * object that lies would push that discovery out to a dozen call sites. The
     * row can only have been written by something that bypassed this layer.
     */
    public function test_reconstitute_refuses_a_row_with_no_contact_detail(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/has neither an email nor a phone/');

        LeadEntity::reconstitute(7, 'Grace', null, '  ', null, 'new', 'form', null, $this->now());
    }

    // ----------------------------------------------------------------- mutations

    /**
     * A status change returns a new entity and leaves the original alone.
     */
    public function test_with_status_returns_a_new_entity(): void
    {
        $lead = $this->validLead();
        $result = $lead->withStatus('won', $this->now());

        $this->assertTrue($result->isOk());
        /** @var LeadEntity $updated */
        $updated = $result->value();

        $this->assertNotSame($lead, $updated);
        $this->assertSame('won', $updated->status);
        $this->assertSame(LeadEntity::STATUS_NEW, $lead->status, 'The original must be untouched.');
    }

    /**
     * An unsupported status is refused, and the message lists what would work.
     *
     * A row holding `'Won'` is invisible to a query for `'won'` with nothing to
     * say why, so the value is checked at the boundary rather than stored.
     */
    public function test_an_unsupported_status_is_refused(): void
    {
        $result = $this->validLead()->withStatus('Won', $this->now());

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('won', $result->errors()['status'][0]);
    }

    /**
     * Setting the status it already has returns the same instance.
     *
     * Identity rather than equality, because callers compare identity to decide
     * whether a write is needed — a no-op transition that produced a new object
     * would cost an `UPDATE` and bump `updated_at` for a change nobody made.
     */
    public function test_a_no_op_status_change_returns_the_same_instance(): void
    {
        $lead = $this->validLead();
        $result = $lead->withStatus(LeadEntity::STATUS_NEW, $this->now());

        $this->assertSame($lead, $result->value());
    }

    /**
     * A lead can be assigned, and released back to the pool.
     */
    public function test_a_lead_can_be_assigned_and_unassigned(): void
    {
        $lead = $this->validLead();

        /** @var LeadEntity $assigned */
        $assigned = $lead->withAssignee(4, $this->now())->value();

        $this->assertSame(4, $assigned->assignedTo);
        $this->assertTrue($assigned->isAssigned());
        $this->assertTrue($assigned->isAssignedTo(4));
        $this->assertFalse($assigned->isAssignedTo(5));

        /** @var LeadEntity $released */
        $released = $assigned->withAssignee(null, $this->now())->value();

        $this->assertNull($released->assignedTo);
        $this->assertFalse($released->isAssigned());
    }

    /**
     * A change moves `updated_at` and leaves `created_at` where it was.
     */
    public function test_a_mutation_moves_the_update_time_and_preserves_the_creation_time(): void
    {
        $lead = $this->validLead();
        $later = new DateTimeImmutable('2026-11-08 15:30:00', new DateTimeZone('UTC'));

        /** @var LeadEntity $updated */
        $updated = $lead->withStatus('won', $later)->value();

        $this->assertEquals($lead->createdAt, $updated->createdAt);
        $this->assertEquals($later, $updated->updatedAt);
    }

    /**
     * An id is stamped once and cannot be reassigned.
     *
     * Overwriting an id is always a bug — a save against the wrong row, a mapping
     * that crossed two records — and it is the kind that silently reassigns
     * somebody else's data rather than failing.
     */
    public function test_with_id_stamps_an_id_once(): void
    {
        $lead = $this->validLead();

        /** @var LeadEntity $stamped */
        $stamped = $lead->withId(7)->value();

        $this->assertSame(7, $stamped->id);
        $this->assertTrue($stamped->exists());

        $again = $stamped->withId(8);

        $this->assertTrue($again->isFailure());
        $this->assertSame('id_already_assigned', $again->errorCode());
    }

    /**
     * A non-positive id is refused rather than stamped.
     */
    public function test_with_id_refuses_a_non_positive_id(): void
    {
        $result = $this->validLead()->withId(0);

        $this->assertTrue($result->isFailure());
        $this->assertSame('invalid_id', $result->errorCode());
    }

    // ------------------------------------------------------------------ matching

    /**
     * The email comparison ignores case, because email addresses do.
     */
    public function test_has_email_matches_case_insensitively(): void
    {
        $lead = $this->validLead();

        $this->assertTrue($lead->hasEmail('ada@example.test'));
        $this->assertTrue($lead->hasEmail('ADA@EXAMPLE.TEST'));
        $this->assertFalse($lead->hasEmail('grace@example.test'));
    }

    /**
     * A lead with no email does not match an empty query.
     *
     * The null case, and the one a `strcasecmp` written without a guard gets
     * wrong: `strcasecmp(null, '')` is `0`, so a phone-only lead would report
     * that it has the empty address.
     */
    public function test_has_email_is_false_for_a_lead_with_no_email(): void
    {
        $lead = LeadEntity::create('Ada', null, '555-0100', 'webhook', now: $this->now())->value();

        $this->assertFalse($lead->hasEmail(''));
        $this->assertFalse($lead->hasEmail('ada@example.test'));
    }

    // -------------------------------------------------------------------- output

    /**
     * `toArray()` exposes the schema names, and formats dates as ISO 8601.
     */
    public function test_to_array_exposes_the_stored_shape(): void
    {
        $lead = $this->validLead();
        $array = $lead->toArray();

        $this->assertNull($array['id']);
        $this->assertSame('Ada Lovelace', $array['name']);
        $this->assertSame('ada@example.test', $array['email']);
        $this->assertSame('Analytical Engines Ltd', $array['company_name']);
        $this->assertSame(LeadEntity::STATUS_NEW, $array['status']);
        $this->assertNull($array['assigned_to']);
        $this->assertSame('2026-11-08T12:00:00+00:00', $array['created_at']);
    }

    /**
     * The declared limits are published as data, so a validator reads them.
     */
    public function test_limits_match_the_constants(): void
    {
        $limits = LeadEntity::limits();

        $this->assertSame(LeadEntity::MAX_NAME_LENGTH, $limits['name']['max']);
        $this->assertSame(LeadEntity::MAX_EMAIL_LENGTH, $limits['email']['max']);
        $this->assertSame(LeadEntity::MAX_SOURCE_LENGTH, $limits['source']['max']);
    }
}
