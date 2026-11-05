<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Note;

use Core\Note\Domain\NoteEntity;
use PHPUnit\Framework\TestCase;

/**
 * The note entity, on its own.
 *
 * Nothing here boots WordPress or the container. The entity's validation is the
 * domain's own rule set — not a validator running over an array — so if these
 * tests needed a stub to pass, the rule would have escaped into infrastructure.
 */
final class NoteEntityTest extends TestCase
{
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-15 09:00:00');
    }

    private function existing(int $userId = 7): NoteEntity
    {
        return NoteEntity::reconstitute(12, 'Existing', 'Body', $userId, $this->now());
    }

    // -------------------------------------------------------------------- create

    public function test_create_builds_a_note_without_an_id(): void
    {
        $result = NoteEntity::create('Title', 'Body', 7, $this->now());

        $this->assertTrue($result->isOk());

        /** @var NoteEntity $note */
        $note = $result->value();

        $this->assertNull($note->id);
        $this->assertFalse($note->exists(), 'A note with no id is not stored yet.');
        $this->assertSame('Title', $note->title);
        $this->assertSame('Body', $note->content);
        $this->assertSame(7, $note->userId);
    }

    /**
     * A note that has never been edited carries no separate modification time, so
     * both stamps are the same instant rather than one being absent.
     */
    public function test_create_stamps_created_and_updated_with_the_same_instant(): void
    {
        $note = NoteEntity::create('Title', 'Body', 7, $this->now())->value();

        $this->assertSame($note->createdAt, $note->updatedAt);
    }

    public function test_create_rejects_an_empty_title(): void
    {
        $result = NoteEntity::create('', 'Body', 7);

        $this->assertTrue($result->isFailure());
        $this->assertSame(422, $result->status());
        $this->assertArrayHasKey('title', $result->errors());
    }

    /**
     * A title of spaces is not a title. Trimming happens before the emptiness
     * check, or a form submitted with the space bar produces a note with a blank
     * heading that reads as a rendering bug.
     */
    public function test_create_rejects_a_whitespace_only_title(): void
    {
        $this->assertTrue(NoteEntity::create("  \t ", 'Body', 7)->isFailure());
    }

    public function test_create_trims_the_title_before_measuring_it(): void
    {
        $note = NoteEntity::create('   Padded   ', 'Body', 7)->value();

        $this->assertSame('Padded', $note->title);
    }

    public function test_create_rejects_a_title_over_the_limit(): void
    {
        $result = NoteEntity::create(str_repeat('a', NoteEntity::MAX_TITLE_LENGTH + 1), 'Body', 7);

        $this->assertTrue($result->isFailure());
        $this->assertArrayHasKey('title', $result->errors());
    }

    /**
     * The boundary itself must be allowed, or the message and the rule disagree
     * by one and the user is told their valid input is invalid.
     */
    public function test_create_accepts_a_title_exactly_at_the_limit(): void
    {
        $this->assertTrue(
            NoteEntity::create(str_repeat('a', NoteEntity::MAX_TITLE_LENGTH), 'Body', 7)->isOk()
        );
    }

    public function test_create_rejects_content_over_the_limit(): void
    {
        $result = NoteEntity::create('Title', str_repeat('a', NoteEntity::MAX_CONTENT_LENGTH + 1), 7);

        $this->assertTrue($result->isFailure());
        $this->assertArrayHasKey('content', $result->errors());
    }

    /**
     * Measurement is in characters, not bytes. A byte count would reject a
     * perfectly ordinary non-Latin title at roughly a third of the stated limit.
     */
    public function test_the_title_limit_counts_characters_not_bytes(): void
    {
        // 255 multibyte characters, 765 bytes in UTF-8.
        $title = str_repeat('é', NoteEntity::MAX_TITLE_LENGTH);

        $this->assertSame(NoteEntity::MAX_TITLE_LENGTH, mb_strlen($title));
        $this->assertGreaterThan(NoteEntity::MAX_TITLE_LENGTH, strlen($title));
        $this->assertTrue(NoteEntity::create($title, 'Body', 7)->isOk());
    }

    /**
     * An ownerless note cannot be authorized, so it is refused rather than stored
     * as an orphan no capability check can match.
     */
    public function test_create_rejects_a_note_with_no_owner(): void
    {
        $this->assertTrue(NoteEntity::create('Title', 'Body', 0)->isFailure());
        $this->assertTrue(NoteEntity::create('Title', 'Body', -1)->isFailure());
    }

    /**
     * Both bad fields are reported in one pass. Returning only the first makes
     * the user fix them one submission apart.
     */
    public function test_create_reports_every_failing_field_at_once(): void
    {
        $result = NoteEntity::create('', str_repeat('a', NoteEntity::MAX_CONTENT_LENGTH + 1), 0);

        $this->assertTrue($result->isFailure());
        $this->assertArrayHasKey('title', $result->errors());
        $this->assertArrayHasKey('content', $result->errors());
        $this->assertArrayHasKey('user_id', $result->errors());
    }

    /**
     * An empty body is a legitimate note — a title and a to-do. Only the title is
     * required, and a rule that demanded content would refuse a valid use.
     */
    public function test_create_accepts_empty_content(): void
    {
        $this->assertTrue(NoteEntity::create('Title', '', 7)->isOk());
    }

    // ------------------------------------------------------------------- limits

    /**
     * The limits are published as data so a request class can read them instead of
     * restating `max:255`. A second copy of the number is one that drifts, and the
     * drift shows up as a validator accepting what the entity then refuses.
     */
    public function test_limits_publish_the_rules_a_validator_needs(): void
    {
        $this->assertSame(
            ['title' => ['max' => NoteEntity::MAX_TITLE_LENGTH], 'content' => ['max' => NoteEntity::MAX_CONTENT_LENGTH]],
            NoteEntity::limits()
        );
    }

    // ---------------------------------------------------------------- withTitle

    public function test_with_title_returns_a_new_note_and_leaves_the_original(): void
    {
        $original = $this->existing();
        $result = $original->withTitle('Changed', new \DateTimeImmutable('2026-02-01 10:00:00'));

        $this->assertTrue($result->isOk());

        /** @var NoteEntity $changed */
        $changed = $result->value();

        $this->assertNotSame($original, $changed);
        $this->assertSame('Existing', $original->title, 'The original must be untouched.');
        $this->assertSame('Changed', $changed->title);
    }

    public function test_with_title_preserves_every_other_field(): void
    {
        $original = NoteEntity::reconstitute(12, 'Existing', 'Body', 7, $this->now());

        /** @var NoteEntity $changed */
        $changed = $original->withTitle('Changed', new \DateTimeImmutable('2026-02-01'))->value();

        $this->assertSame(12, $changed->id);
        $this->assertSame('Body', $changed->content);
        $this->assertSame(7, $changed->userId);
        $this->assertSame($original->createdAt, $changed->createdAt, 'Created time is immutable.');
    }

    /**
     * A change must never leave a note claiming it was never touched. Routing
     * every mutation through one copy step is what makes that impossible to
     * forget rather than merely unlikely.
     */
    public function test_with_title_advances_updated_at_but_not_created_at(): void
    {
        $original = NoteEntity::reconstitute(12, 'Existing', 'Body', 7, new \DateTimeImmutable('2026-01-01'));
        $later = new \DateTimeImmutable('2026-06-01');

        /** @var NoteEntity $changed */
        $changed = $original->withTitle('Changed', $later)->value();

        $this->assertSame($later, $changed->updatedAt);
        $this->assertSame($original->createdAt, $changed->createdAt);
    }

    public function test_with_title_rejects_an_empty_title(): void
    {
        $result = $this->existing()->withTitle('   ');

        $this->assertTrue($result->isFailure());
        $this->assertSame(422, $result->status());
        $this->assertArrayHasKey('title', $result->errors());
    }

    public function test_with_title_rejects_a_title_over_the_limit(): void
    {
        $this->assertTrue(
            $this->existing()->withTitle(str_repeat('a', NoteEntity::MAX_TITLE_LENGTH + 1))->isFailure()
        );
    }

    /**
     * An edit that changes nothing returns the *same instance*, not an equal copy.
     * Callers compare identity to decide whether a write is needed, and a no-op
     * edit that produced a new object would cost an UPDATE and bump `updated_at`.
     */
    public function test_with_title_returns_the_same_instance_when_unchanged(): void
    {
        $note = $this->existing();
        $result = $note->withTitle('Existing', new \DateTimeImmutable('2026-06-01'));

        $this->assertTrue($result->isOk());
        $this->assertSame($note, $result->value());
        $this->assertNotSame(new \DateTimeImmutable('2026-06-01'), $note->updatedAt);
    }

    // -------------------------------------------------------------- withContent

    public function test_with_content_returns_a_new_note(): void
    {
        $original = $this->existing();

        /** @var NoteEntity $changed */
        $changed = $original->withContent('Different', new \DateTimeImmutable('2026-02-01'))->value();

        $this->assertNotSame($original, $changed);
        $this->assertSame('Body', $original->content);
        $this->assertSame('Different', $changed->content);
        $this->assertSame('Existing', $changed->title);
    }

    public function test_with_content_rejects_content_over_the_limit(): void
    {
        $result = $this->existing()->withContent(str_repeat('a', NoteEntity::MAX_CONTENT_LENGTH + 1));

        $this->assertTrue($result->isFailure());
        $this->assertArrayHasKey('content', $result->errors());
    }

    public function test_with_content_returns_the_same_instance_when_unchanged(): void
    {
        $note = $this->existing();

        $this->assertSame($note, $note->withContent('Body')->value());
    }

    public function test_content_may_be_emptied(): void
    {
        $this->assertTrue($this->existing()->withContent('')->isOk());
    }

    // ------------------------------------------------------------------ withId

    public function test_with_id_stamps_a_freshly_created_note(): void
    {
        $created = NoteEntity::create('Title', 'Body', 7, $this->now())->value();

        /** @var NoteEntity $stamped */
        $stamped = $created->withId(99)->value();

        $this->assertSame(99, $stamped->id);
        $this->assertTrue($stamped->exists());
    }

    /**
     * Reassigning an id always means something went wrong upstream — a save
     * against the wrong row, a mapping that crossed two records. Silently
     * accepting it would quietly move one user's note onto another's id.
     */
    public function test_with_id_refuses_to_restamp_an_existing_note(): void
    {
        $result = $this->existing()->withId(50);

        $this->assertTrue($result->isFailure());
        $this->assertSame('id_already_assigned', $result->errorCode());
        $this->assertStringContainsString('12', $result->message());
    }

    public function test_with_id_refuses_a_non_positive_id(): void
    {
        $created = NoteEntity::create('Title', 'Body', 7, $this->now())->value();

        $this->assertSame('invalid_id', $created->withId(0)->errorCode());
        $this->assertSame('invalid_id', $created->withId(-4)->errorCode());
    }

    // ------------------------------------------------------------------ ownership

    public function test_ownership_is_decided_by_the_user_id(): void
    {
        $note = $this->existing(userId: 7);

        $this->assertTrue($note->isOwnedBy(7));
        $this->assertFalse($note->isOwnedBy(8));
    }

    // ------------------------------------------------------------------ toArray

    public function test_to_array_exposes_the_domain_fields(): void
    {
        $array = NoteEntity::reconstitute(
            12,
            'Title',
            'Body',
            7,
            new \DateTimeImmutable('2026-01-15 09:00:00')
        )->toArray();

        $this->assertSame(
            [
                'id' => 12,
                'title' => 'Title',
                'content' => 'Body',
                'user_id' => 7,
                'created_at' => '2026-01-15T09:00:00+00:00',
                'updated_at' => '2026-01-15T09:00:00+00:00',
            ],
            $array
        );
    }

    /**
     * Dates leave as ISO 8601 strings. Handing a `DateTimeImmutable` to
     * `json_encode()` serializes the object's internal structure, which is a PHP
     * implementation detail no API client should have to parse.
     */
    public function test_to_array_serializes_dates_as_strings(): void
    {
        $array = $this->existing()->toArray();

        $this->assertIsString($array['created_at']);
        $this->assertIsString($array['updated_at']);
        $this->assertIsArray(json_decode((string) json_encode($array), true));
    }

    public function test_to_array_reports_a_null_id_for_an_unsaved_note(): void
    {
        $array = NoteEntity::create('Title', 'Body', 7, $this->now())->value()->toArray();

        $this->assertNull($array['id']);
    }
}
