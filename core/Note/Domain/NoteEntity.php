<?php
declare(strict_types=1);

namespace Core\Note\Domain;

/**
 * A note, as the business understands one.
 *
 * ## Why this is not the database row
 *
 * The table this is stored in has columns this class does not have, and will
 * grow more: an `updated_at`, a soft-delete flag, a `blog_id` for multisite, a
 * serialized `meta` blob. None of those are part of what a note *is* — they are
 * how one particular schema records it. An entity that mirrored the table would
 * force every business rule to be written against a storage decision, and the
 * first schema change would reach the domain.
 *
 * So the entity holds what the business reasons about, and the repository maps
 * one to the other. A column added tomorrow is invisible here until something
 * actually needs it.
 *
 * ## Why `readonly`, and why `with*()` instead of setters
 *
 * A mutable entity handed to three collaborators is a value any of them can
 * change, and the bug that follows is unreproducible because it depends on call
 * order. Immutability makes each state a distinct value: `$note->withTitle('x')`
 * returns a new note and leaves the original alone, so a caller holding a note
 * knows nothing can change it underneath them.
 *
 * `final` for the same reason in the other direction — a subclass could override
 * a `with*()` method and return a mutated `$this`, and every guarantee above
 * would be a subclass's honour system. If a note needs behaviour of its own,
 * that behaviour goes here.
 *
 * ## The constructor is not the way to create one
 *
 * {@see create()} and {@see reconstitute()} exist because "a note the user is
 * asking for" and "a note read back out of storage" are different acts with
 * different rules, and the constructor cannot tell them apart. See each method.
 *
 * ## Time
 *
 * `createdAt` and `updatedAt` are immutable `DateTimeImmutable`, not strings and
 * not `DateTime`. A mutable date handed out of a readonly property is a hole in
 * the immutability: the property cannot be reassigned, but the object it holds
 * can be moved forward by anyone holding it. Strings would push formatting and
 * timezone decisions onto every class that touches a note, which is how a
 * payload ends up with the server's timezone baked into it.
 */
final readonly class NoteEntity
{
    /**
     * The longest title the domain accepts, in characters.
     *
     * A domain limit rather than a storage one — the column could hold more. It
     * exists because an unbounded string that reaches a page heading, an email
     * subject and a log line is a denial-of-service shape, and because a limit is
     * one of the few validation rules that belongs where the data does rather
     * than in a request class that a console command would bypass.
     */
    public const MAX_TITLE_LENGTH = 255;

    /**
     * A bounded body, for the same reason.
     */
    public const MAX_CONTENT_LENGTH = 65535;

    /**
     * The limits, as data, so a validator can read them rather than restate them.
     *
     * A request class that hardcodes `max:255` is a second copy of this rule, and
     * the two diverge silently: the validator accepts a 300-character title, the
     * entity refuses it, and the user gets an error page rather than a field
     * message. Exposing the rules as values means the outer layer asks instead of
     * guessing.
     *
     * @return array{title: array{max: int}, content: array{max: int}}
     */
    public static function limits(): array
    {
        return [
            'title' => ['max' => self::MAX_TITLE_LENGTH],
            'content' => ['max' => self::MAX_CONTENT_LENGTH],
        ];
    }

    private function __construct(
        public ?int $id,
        public string $title,
        public string $content,
        public int $userId,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * An existing note, read back out of storage.
     *
     * Only the repository calls this. Every field is trusted: the id came from a
     * primary key, and the timestamps came from columns the database wrote. A
     * factory that re-validated here would be checking the storage layer's work
     * rather than a caller's input, and would fail on historical rows that
     * predate a rule added since.
     *
     * `$updatedAt` defaults to `$createdAt` because a note that has never been
     * edited carries no separate modification time, and passing the same value
     * twice at every call site is noise.
     */
    public static function reconstitute(
        int $id,
        string $title,
        string $content,
        int $userId,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $updatedAt = null,
    ): self {
        return new self($id, $title, $content, $userId, $createdAt, $updatedAt ?? $createdAt);
    }

    /**
     * A note the business is about to store for the first time.
     *
     * ## Why this returns a `Result` and not a `NoteEntity`
     *
     * An invalid title is not a programming error, it is a user who typed too
     * much — an ordinary outcome, and one this layer is the only place holding
     * the rule that decides it. Returning the reason is what lets the caller
     * report which field was wrong.
     *
     * ## Why the factories are constructed here with `new self`
     *
     * `Result` lives in `Core\Shared`, which this class may reference — the
     * dependency rule is that `core/` does not reach *out* to the hosting
     * platform or to `app/`, not that its own namespaces cannot see each other.
     *
     * @return Result The new entity on success; a validation failure naming the
     *                offending field otherwise.
     */
    public static function create(
        string $title,
        string $content,
        int $userId,
        ?\DateTimeImmutable $now = null,
    ): \Core\Shared\Result {
        $errors = [];

        $title = trim($title);

        // Trimmed before it is measured, so a title of spaces plus a real word is
        // judged on the word. An empty title is refused rather than defaulted:
        // "Untitled" invented here would be indistinguishable from one the user
        // typed, and the user would not know their input was discarded.
        if ($title === '') {
            $errors['title'][] = 'A note must have a title.';
        } elseif (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            $errors['title'][] = sprintf(
                'A title may be at most %d characters; %d were given.',
                self::MAX_TITLE_LENGTH,
                mb_strlen($title)
            );
        }

        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            $errors['content'][] = sprintf(
                'The content may be at most %d characters; %d were given.',
                self::MAX_CONTENT_LENGTH,
                mb_strlen($content)
            );
        }

        // A note with no owner cannot be authorized, so it is refused rather than
        // stored as an orphan that no capability check can ever match.
        if ($userId <= 0) {
            $errors['user_id'][] = 'A note must belong to a user.';
        }

        if ($errors !== []) {
            return \Core\Shared\Result::validation($errors);
        }

        $now ??= new \DateTimeImmutable();

        return \Core\Shared\Result::ok(new self(null, $title, $content, $userId, $now, $now));
    }

    /**
     * The same note with a different title, or the reason it cannot have one.
     *
     * Returns a `Result` rather than an entity for the same reason {@see create()}
     * does, and it is the reason `with*()` here is not the plain copy-method the
     * name suggests: a method that could only succeed would have no way to report
     * a 256-character title, and the caller would have to validate separately —
     * which is exactly the second copy of the rule this avoids.
     *
     * `$now` is a parameter rather than a call to `new \DateTimeImmutable()`
     * inside, so the caller decides when "now" is. Two edits in one request must
     * carry the same timestamp, and a test that cannot control the clock cannot
     * assert on it.
     *
     * @return \Core\Shared\Result The updated entity, or a validation failure.
     */
    public function withTitle(string $title, ?\DateTimeImmutable $now = null): \Core\Shared\Result
    {
        $title = trim($title);

        if ($title === '') {
            return \Core\Shared\Result::validation(['title' => ['A note must have a title.']]);
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return \Core\Shared\Result::validation(['title' => [sprintf(
                'A title may be at most %d characters; %d were given.',
                self::MAX_TITLE_LENGTH,
                mb_strlen($title)
            )]]);
        }

        // An unchanged value returns `$this` rather than an equal copy. Callers
        // compare identity to decide whether a write is needed, and a no-op edit
        // that produced a new object would look like a change and cost an UPDATE
        // plus a bump to `updatedAt`.
        if ($title === $this->title) {
            return \Core\Shared\Result::ok($this);
        }

        return \Core\Shared\Result::ok($this->with(['title' => $title], $now));
    }

    /**
     * The same note with different content.
     *
     * @return \Core\Shared\Result The updated entity, or a validation failure.
     */
    public function withContent(string $content, ?\DateTimeImmutable $now = null): \Core\Shared\Result
    {
        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            return \Core\Shared\Result::validation(['content' => [sprintf(
                'The content may be at most %d characters; %d were given.',
                self::MAX_CONTENT_LENGTH,
                mb_strlen($content)
            )]]);
        }

        if ($content === $this->content) {
            return \Core\Shared\Result::ok($this);
        }

        return \Core\Shared\Result::ok($this->with(['content' => $content], $now));
    }

    /**
     * The same note, now carrying the id the database assigned it.
     *
     * A note is created without an id — the storage layer owns that value, and a
     * domain object that invented one would be guessing at an auto-increment it
     * cannot see. This is the one transition that fills it in, and it is why
     * `$id` is nullable on an otherwise complete entity: there is exactly one
     * moment in a note's life when it has no id, and the type says so.
     *
     * Refuses to re-stamp a note that already has one. Overwriting an id is
     * always a bug — a save that ran against the wrong row, a mapping that
     * crossed two records — and it is the kind of bug that silently reassigns
     * someone else's data rather than failing.
     *
     * @return Result The stamped entity, or a failure if it already had an id.
     */
    public function withId(int $id): \Core\Shared\Result
    {
        if ($id <= 0) {
            return \Core\Shared\Result::fail('A note id must be a positive integer.', 500, [], 'invalid_id');
        }

        if ($this->id !== null) {
            return \Core\Shared\Result::fail(
                sprintf(
                    'This note already has id %d; it cannot be reassigned %d. An id is assigned '
                    . 'once, by the storage layer.',
                    $this->id,
                    $id
                ),
                500,
                [],
                'id_already_assigned'
            );
        }

        return \Core\Shared\Result::ok($this->with(['id' => $id]));
    }

    /**
     * Whether this note has been persisted.
     *
     * The question every caller actually asks — "do I INSERT or UPDATE?" — asked
     * of the entity that knows, rather than phrased as `$note->id === null` at
     * each site.
     */
    public function exists(): bool
    {
        return $this->id !== null;
    }

    /**
     * Whether this note belongs to the given user.
     *
     * Ownership is a domain question — it is a fact about the note and the user,
     * not about the request — so it is answered here and not in a middleware.
     * The middleware asks the service, the service asks the entity, and the rule
     * exists once.
     */
    public function isOwnedBy(int $userId): bool
    {
        return $this->userId === $userId;
    }

    /**
     * The shared copy step behind every `with*()` method.
     *
     * ## Why one private method and not six hand-written copies
     *
     * Each `with*()` differs only in which fields it replaces, and the rest of
     * the construction is identical — five arguments repeated six times is five
     * places to forget one when a field is added. Routing every change through
     * here means a new field has exactly one construction site to update.
     *
     * `updatedAt` is set here rather than passed from each caller so that no
     * mutation can forget it, which would leave a changed note claiming it had
     * never been touched.
     *
     * @param array<string, mixed> $changes
     */
    private function with(array $changes, ?\DateTimeImmutable $now = null): self
    {
        $now ??= new \DateTimeImmutable();

        return new self(
            $changes['id'] ?? $this->id,
            $changes['title'] ?? $this->title,
            $changes['content'] ?? $this->content,
            $changes['userId'] ?? $this->userId,
            $this->createdAt,
            $now,
        );
    }

    /**
     * The note as a plain array, for a resource mapper or a log line.
     *
     * Deliberately `snake_case` and deliberately not `toJson()`. The array is the
     * shape a transport will publish, and the decisions that belong to a
     * transport — which fields a client may see, how a date is formatted, whether
     * `user_id` is exposed at all — are not made here. This is the superset; the
     * resource that wraps it is what chooses.
     *
     * The dates are ISO 8601 strings rather than `DateTimeImmutable` objects
     * because an array that reaches `json_encode()` would otherwise serialize a
     * date object's *internal* structure, which is a PHP implementation detail no
     * client should receive.
     *
     * @return array{
     *     id: int|null, title: string, content: string, user_id: int,
     *     created_at: string, updated_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'user_id' => $this->userId,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
