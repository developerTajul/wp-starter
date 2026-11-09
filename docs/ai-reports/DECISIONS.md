# DECISIONS

Decisions taken while executing a plan or an ad-hoc task that the rule files did not
settle. Each entry records what was decided, why, and the AGENTS.md change it implies.

AGENTS.md is never edited while recording a decision. A proposed change is a request, not
an edit.

---

## D-2026-11-09-01 — `whereGroup()` added to the query builder

**Step:** `query-builder-upgrade/01`.

**Decision.** `QueryBuilderInterface` and `WpFluentQueryBuilder` gained
`whereGroup(callable $callback): static`, which compiles its callback's conditions as one
parenthesised term. This is beyond the method list the plan's Phase 1 gives.

**Why.** Without it the plan's own Phase 4 target cannot be written correctly. The Company
search is `name LIKE ? OR industry LIKE ?`, and the builder's clause list is flat: an
`orWhere()` appended after the search group conjoins at the wrong precedence. SQL binds
`AND` tighter than `OR`, so

```
status = 'active' AND name LIKE ? OR industry LIKE ?
```

is read by MySQL as `(status = 'active' AND name LIKE ?) OR industry LIKE ?` — the search
matches every row whose industry contains the term, whatever the status. The alternative,
a group method, is the smallest change that keeps the predicate a value the builder
composes. Exclusion: building the group by string concatenation in the repository, which
is the raw-SQL escape hatch D6 exists to close.

**Test that pins it.** `test_a_group_keeps_its_or_away_from_the_outer_conditions` seeds
the row an unbracketed `OR` would wrongly return, so the leak shows as a wrong row rather
than as a passing assertion.

**Proposed AGENTS.md change.** In `MANDATORY`/rule 10's Fluent Database section, list the
query builder's read vocabulary explicitly — `orWhere`, `whereGroup`, `whereIn`,
`whereNotIn`, `whereNull`, `whereNotNull`, `join`, `leftJoin` — so a future step does not
re-derive it. The `whereGroup` rule to state: *a condition containing an `OR` MUST be
wrapped in `whereGroup()` when anything else is conjoined to it.*

---

## D-2026-11-09-02 — `whereIn()` refuses an empty list; `whereNotIn()` drops the clause

**Decision.** An empty `whereIn()` throws `QueryException`. An empty `whereNotIn()` adds no
condition at all.

**Why.** The asymmetry is a fact about the predicates, not a preference. `IN ()` and
`NOT IN ()` are both MySQL syntax errors, so neither can be compiled. But the questions
differ: "match one of nothing" has no true answer, while "outside a set that turned out to
be empty" is true for every row. Refusing the first catches a filter that came out empty —
a caller mistake. Dropping the second states what the caller asked for. Compiling the
first to a never-matching clause would hide the mistake behind an empty page.

**Consequence for callers.** A repository that builds an id list must check for the empty
case itself; `WPFluentCompanyReadRepository::activitiesGroupedByDeal()` returns early when
there are no deals.

**Proposed AGENTS.md change.** None required. Recorded so a later step does not
"fix" the asymmetry into a symmetry.

---

## D-2026-11-09-03 — Identifiers may carry an `AS` alias

**Decision.** The builder's column grammar accepts `identifier [AS identifier]`, and the
identifier itself may be `table.column`. Aliases are quoted part by part.

**Why.** A joined read needs them. Two tables in one `SELECT` can both have `id`, and the
row's key must distinguish them — `deal_stages.id AS stage_id`,
`users.display_name AS user_name`. Without alias support the Company detail reads could not
be expressed on the builder, and Phase 4 would have forced raw SQL back for exactly the
reads the plan asks to convert.

**Security note.** An alias is statement structure and is pasted, not bound. It is
validated by the same pattern as a column name, so a string carrying SQL is refused.

**Proposed AGENTS.md change.** None. This is a detail of the builder's own grammar.

---

## D-2026-11-09-04 — `addcslashes($term, '%_\\')` stands in for `$wpdb->esc_like()`

**Decision.** The repositories escape a `LIKE` term with `addcslashes($term, '%_\\')`
before adding wildcards, rather than `$wpdb->esc_like()`.

**Why.** `esc_like()` is a WordPress function, and D6 keeps the repositories on the fluent
builder with no WordPress dependency beyond the table prefix. The two do the same job —
escape `%`, `_` and the backslash so the term's own characters match literally — and
`addcslashes()` is the PHP built-in that does it. The escaping happens **before** the
wildcards are added, which is the order that matters: escaping after would escape the
caller's own `%`.

**Risk accepted.** `esc_like()` also escapes the escape character in whatever way the
active MySQL connection requires; `addcslashes` always uses a backslash. On the default
`NO_BACKSLASH_ESCAPES`-off configuration the two agree, which is what WordPress ships.

**Proposed AGENTS.md change.** Add to the D6 entry: *a `LIKE` term is escaped to match its
own `%` and `_` literally before wildcards are added; use `addcslashes($term, '%_\\')`, not
`$wpdb->esc_like()`.*

---

## D-2026-11-09-05 — Six unwired `$wpdb` repositories left unconverted

**Decision.** `LeadTimelineReadRepository`, `LeadDealReadRepository`,
`LeadNoteReadRepository`, `LeadNoteWriteRepository`, `LeadConversionWriteRepository` and
`LeadOwnerFormOptionsRepository` keep their raw `$wpdb` code. Not changed by this step.

**Why.** The plan names the Company read repository and "any other Read Repositories like
Lead", which `LeadReadRepository` satisfies. These six have no binding in any provider, no
reference from any controller, route, service, config or test, and no test of their own.
Converting them would be a large change to code no read path exercises, which cannot be
verified by running anything — the opposite of what the plan's Phase 5 asks for. Left for a
separate decision on whether they should be wired at all.

**Proposed AGENTS.md change.** None. This is a scope call, recorded so the next session
does not read the leftover `$wpdb` as an oversight.

---

## D-2026-11-09-06 — Phase 5's table prefixing is not applied

> ## ⚠ SUPERSEDED — 2026-11-09, by report `02-query-builder-mysql-compatibility.md`
>
> **This decision was WRONG and has been reversed.** Qualifiers **are** now prefixed;
> `quoteColumn()` emits `` `wp_companies`.`name` ``.
>
> The error: it validated a claim about **MySQL** against **`FakeWpdb`**. The fake tolerates
> bare qualifiers (`mergeJoinedRow()` keys a joined column under both `companies.name` and
> `wp_companies.name`), which real MySQL does not. Measured on live MySQL 5.7.33:
> `` FROM `wp_deals` … `deals`.`id` `` fails with **error 1054, Unknown column 'deals.id'**.
> The prefixed form returns rows.
>
> The "proposed plan amendment" below is **withdrawn**. The text is kept as a record of the
> reasoning that produced the wrong answer — reasoning from a test double to the database it
> stands in for.
>
> Lesson recorded: a claim about database behaviour must be tested against the database. A
> green suite proved nothing here, because the fake was lenient in exactly the place that
> mattered.

**Decision.** `WpFluentQueryBuilder::quoteColumn()` does **not** prepend `$this->prefix()` to
the qualifier of a dot-notated column. A qualified reference is quoted as the caller wrote
it: `companies.name` → `` `companies`.`name` ``.

**Why.** The plan asks for prefixing, but qualifies it with "(you may need to maintain a
simple array of applied aliases … to differentiate between actual table names and aliases)".
That caveat is the whole problem, and it is not solvable as described:

1. This builder emits the join's `ON` clause with the **bare** qualifier —
   `` INNER JOIN `wp_companies` ON `companies`.`name` = `leads`.`company_name` ``. MySQL
   resolves `companies` as the alias the `JOIN` itself created. Prefixing only the other
   clauses would give one column two spellings, only one of which resolves.
2. Every real call site is unprefixed: `WPFluentCompanyReadRepository` uses
   `orderByDesc('company_contact.is_primary')`, `orderBy('contacts.name')`,
   `orderByDesc('deals.created_at')`; the integration tests use `select('leads.name')` and
   `orderBy('companies.name')`. Prefixing rewrites `leads.name` → `wp_leads.name` and breaks
   all of them.
3. Laravel does not prefix qualifiers in `select()`/`orderBy()` either (F2 parity).

The plan's Phase 4 and its Phase 5 note are in direct conflict — Phase 4 fixes `orderBy`
quoting assuming a bare qualifier; Phase 5's note assumes prefixing. Resolved in favour of
Phase 4, Laravel parity, and every existing call site.

**Proposed AGENTS.md change.** None to AGENTS.md. Proposed **plan** amendment: drop the
prefixing requirement, or scope it to a bare unqualified column in a non-joined read, where
it is a no-op.

---

## D-2026-11-09-07 — `FakeWpdb::parseOrderBy()` repaired (out-of-plan, required)

**Decision.** `tests/Support/FakeWpdb.php::parseOrderBy()` runs the parsed identifier through
the existing `normalizeIdentifier()` instead of a bare `trim($s, " `\t\n\r\0\x0B")`.

**Why.** Without it, the plan's own Phase 4 change makes an existing test fail — not because
Phase 4 is wrong, but because the fake could not parse the correct SQL.

Measured mechanism (not reasoned):
- `mergeJoinedRow()` keys a joined column under both `companies.name` and `wp_companies.name`.
- `parseOrderBy()` stripped only *edge* backticks, so the **old, broken** SQL
  `` `companies.name` `` happened to reduce to the exact key `companies.name` and sorted
  correctly **by accident**.
- The corrected SQL `` `companies`.`name` `` leaves the inner backticks in place
  (`companies`.`name`), which matches no row key, so `usort` compared `null <=> null`,
  returned `0` for every pair, and preserved insertion order.
- `normalizeIdentifier()` exists in the fake for exactly this and normalises both forms to
  `companies.name`.

Evidence: with the fake unfixed, `test_a_joined_read_with_a_search_group_and_a_page_window`
fails; reverting Phase 4 makes it pass again; with both applied it passes and **both** SQL
forms sort correctly — i.e. it now passes for the right reason.

**Scope note.** `tests/` is outside the plan. The edit was necessary to make the plan's Phase
4 verifiable at all; leaving it would have shipped a red suite that blamed the fix.

**Proposed AGENTS.md change.** None. Recorded because it is a `tests/` edit inside a JOB F
step.

---

## D-2026-11-09-08 — Five tests added to pin `FakeWpdb`'s qualifier strictness

**Step:** `query-builder-bugfix/03`.

**Decision.** Five tests were added to
`tests/Integration/Database/QueryBuilderExecutionTest.php`, handing `FakeWpdb` raw SQL the
builder would not compose. The plan's Phase 3 asks for failing tests to be *fixed*; it does
not ask for new ones.

**Why.** Phase 3's premise did not hold — no test broke, because every qualified read in the
suite goes through the builder, which prefixes a qualifier before composing the statement.
The fake therefore never saw a mismatched qualifier, and the strictness this step exists to
add was **unverified by the suite**: re-applying the leniency left all 1472 tests at
baseline. Shipping that would be a claim with nothing behind it, which the plan's own
"without relying on external databases" item and AGENTS.md's anti-hallucination protocol
both forbid.

Each test was mutation-verified: re-prefixing the qualifier in `assertQualifierIsInScope()`
fails 1; making projection validation row-dependent again fails 3.

**A test was written and then removed.** `test_a_declared_alias_resolves_and_shadows_the_table_name`
asserted `FROM `wp_leads` AS `leads``. The fake does not parse table aliases, so it failed.
Rather than leave it passing for the wrong reason or implement an unexercised capability, it
was deleted and the gap recorded as an open item in report 03. The builder never emits a
table alias, so nothing needs it yet.

**Proposed AGENTS.md change.** None. Recorded because it is a `tests/` addition beyond the
plan's letter, justified by its intent.
