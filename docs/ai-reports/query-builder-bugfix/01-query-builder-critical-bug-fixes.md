# 01 — Query Builder Critical Bug Fixes

**Plan:** `docs/ai-plans/query_builder_bug_fix_plan.md` (all 5 phases)
**Mode:** JOB F (FRAMEWORK) — every changed path is `framework/`, plus one `tests/` file.
**Date:** 2026-11-09
**Result:** DONE, with one plan item deliberately not followed (Phase 5 prefixing) and one
out-of-plan fix required in `tests/Support/FakeWpdb.php` to keep a change verifiable.

## Mode / rule declaration (PROCESS GUARD, before coding)

| Rule | Why it applied |
| --- | --- |
| MODE DETECTION | `framework/Database/Query/**` → **FRAMEWORK**; load 10, 02 |
| 10 F1.1 / F1.2 / F7 | No `App\`, `Core\`, project name or table name may enter `framework/` |
| 10 F2 | Laravel parity — `orderBy()` semantics |
| 10 F9 | `Framework\Database` is public API: method parity between interface and implementation |
| 10 F4 | `final` implementation class; SemVer impact stated |
| 09 H12 | A `framework/` change is a hard stop *unless the prompt names it* — this plan does |
| AGENTS STRICT SCOPE | The plan is the scope; no unprompted fixes |

## What changed

### Phase 1 — `framework/Database/Query/QueryBuilderInterface.php`
- `where()` and `orWhere()` `$value` widened to `string|int|float|bool|null`.
- Docblocks reworded: `null` is accepted **only** so it can be refused with a clear
  `QueryException`, replacing the "deliberately **not** nullable" wording.

### Phase 2 — nested transactions (`WpFluentQueryBuilder`)
- `beginTransaction()` now increments the depth on **every** call; `START TRANSACTION` is
  issued only on the `0 → 1` transition.
- `commit()` decrements; `COMMIT` is issued only when the depth reaches `0`.
- `rollback()` resets the depth to `0` and issues `ROLLBACK` only when a frame was open.

> **Deviation from the plan's implementation hint.** The plan suggested
> `if ($this->transactionDepth++ === 0)`. Taken literally that increments on every call,
> including a nested one, but puts the SQL *before* the increment — workable, yet it hides
> the "counted frame vs. real SQL" split inside a post-increment side effect. The depth is
> incremented on its own line and the guard reads `=== 1`. Same semantics, and the
> `commit()`/`rollback()` halves the plan also specifies now agree with it. This matters:
> the old code *returned early* on a nested `beginTransaction()` without counting, so a
> `begin → begin → commit` sequence issued a real `COMMIT` while a frame was still open.

### Phase 3 — `UPDATE` null & boolean corruption
- `compileAssignment()` now returns **both** the `SET` body and its bound arguments.
  - `null` → the literal `NULL` keyword, **no** placeholder, **no** argument.
  - `bool` → cast to `1`/`0` before binding.
- New `boundValue()`, the counterpart to `placeholderFor()`: a bool is cast here so the
  value and its format cannot drift apart. Wired into `compileAssignment()`,
  `compileComparison()` and `inFragment()`.
- `update()` consumes the returned argument list.

> **This is stricter than the plan's text.** The plan said to skip nulls in
> `compileAssignment()` but left `update()` doing
> `array_merge(array_values($values), $args)`. Once one column binds nothing, that merge is
> positional and **offsets every later placeholder by one**, binding each value to the wrong
> column — strictly worse than the bug being fixed. The arguments had to move into
> `compileAssignment()`. Verified: an update of
> `['name' => 'Ada L', 'company_name' => null, 'status' => 'lost']` writes all three
> correctly.

### Phase 4 — `ORDER BY` identifier quoting
- `self::quoteIdentifier($order['column'])` → `$this->quoteColumn($order['column'])`.
- Emits `` `companies`.`name` `` instead of the invalid `` `companies.name` ``.

### Phase 5 — scope change + call sites (partial, by design)
- `quoteColumn()` and `quoteProjection()` converted from `static` to instance methods.
- All 8 internal call sites updated (`self::` → `$this->`), including
  `array_map($this->quoteProjection(...), …)`.

> **The prefixing half of Phase 5 was NOT implemented. See "Decisions" below.**

## Decisions taken (not covered by the rule files)

### D-2026-11-09-06 — Phase 5's table prefixing is not applied
**Decision.** `quoteColumn()` does **not** prepend `$this->prefix()` to the qualifier of a
dot-notated column. It stays unprefixed, quoted as the caller wrote it.

**Why.** The plan asks for prefixing but adds "(you may need to maintain a simple array of
applied aliases … to differentiate between actual table names and aliases)". That caveat is
the whole problem, and it is not solvable the way the plan describes:

1. This builder emits the join's `ON` clause with the **bare** qualifier —
   `` INNER JOIN `wp_companies` ON `companies`.`name` = `leads`.`company_name` ``. MySQL
   resolves `companies` as the alias the `JOIN` itself created. Prefixing only the
   `ORDER BY`/`SELECT` side would make the same column have two spellings, only one of
   which resolves.
2. Every real call site in the project is **unprefixed**: `WPFluentCompanyReadRepository`
   uses `orderByDesc('company_contact.is_primary')`, `orderBy('contacts.name')`,
   `orderByDesc('deals.created_at')`; the integration tests use `select('leads.name')`,
   `orderBy('companies.name')`. Prefixing would rewrite `leads.name` → `wp_leads.name` and
   break all of them.
3. Laravel does not prefix qualifiers in `select()`/`orderBy()` either — F2 parity.

The plan's Phase 4 and its Phase 5 note are therefore in direct conflict: Phase 4 fixes
`orderBy` quoting on the assumption the qualifier is bare, Phase 5's note assumes prefixing
is correct. Resolved in favour of Phase 4 + Laravel parity + every existing call site.

**Proposed AGENTS.md change.** None to AGENTS.md. Proposed addition to the **plan** for a
future revision: drop the prefixing requirement, or scope it to a *bare, unqualified* column
in a non-joined read, where it is a no-op anyway.

### D-2026-11-09-07 — `FakeWpdb::parseOrderBy()` repaired (out-of-plan, required)
**Decision.** `tests/Support/FakeWpdb.php::parseOrderBy()` now runs the parsed identifier
through the existing `normalizeIdentifier()` instead of a bare
`trim($s, " `\t\n\r\0\x0B")`.

**Why.** Without this, **Phase 4 makes an existing test fail** — not because Phase 4 is
wrong, but because the fake could not parse the correct SQL.

The mechanism, measured rather than reasoned:
- `mergeJoinedRow()` keys a joined column under both `companies.name` and `wp_companies.name`.
- `parseOrderBy()` stripped only *edge* backticks, so the old broken SQL `` `companies.name` ``
  happened to reduce to the exact key `companies.name` and sorted correctly **by accident**.
- The corrected SQL `` `companies`.`name` `` left the inner backticks in place
  (`companies`.`name`), which matches no row key, so `usort` compared `null <=> null`,
  returned `0` for every pair, and left insertion order.
- `normalizeIdentifier()` exists in the fake for exactly this and normalises both forms to
  `companies.name`.

Evidence: with the fix reverted, `test_a_joined_read_with_a_search_group_and_a_page_window`
passes; with Phase 4 applied and the fake unfixed, it fails; with both applied, it passes.
After the fix, **both** SQL forms sort correctly through the fake, so the test now passes for
the right reason.

**Scope note.** `tests/` is outside the plan. This was necessary to make the plan's own
Phase 4 change verifiable at all — leaving it would have shipped a red suite that blamed the
fix. Flagged here rather than done silently.

**Proposed AGENTS.md change.** None. Recorded because it is a `tests/` edit in a JOB F step.

## Verification (evidence, not claims)

Every claim below was produced by executing code, not by reading the diff.

| Phase | Check | Result |
| --- | --- | --- |
| 1 | `where()`/`orWhere()` accept `null` in interface **and** implementation | both `string\|int\|float\|bool\|null` |
| 2 | `begin,begin,begin,commit×3` → exactly one `START TRANSACTION`, one `COMMIT` | PASS |
| 2 | **Old bug:** `begin,begin,commit` issues **no** `COMMIT` while a frame is open | PASS |
| 2 | `transaction()` nesting runs inner callback with no extra SQL | PASS (`outer+inner`) |
| 2 | `transaction()` rethrows and issues `ROLLBACK` | PASS |
| 2 | `rollback()` resets depth fully; bare `commit`/`rollback` emit no SQL | PASS |
| 3 | `update(['company_name' => null, …])` writes SQL `NULL` (not `''`) | PASS |
| 3 | `update(['is_primary' => false])` writes `0` (not `''`); `true` writes `1` | PASS |
| 3 | null in the middle does **not** shift the following bindings | PASS (3 columns correct) |
| 4 | `orderByDesc('companies.name')` emits `` ORDER BY `companies`.`name` DESC `` | PASS |
| 4 | that statement really reorders (`Grace,Ada`) through the fake | PASS (after the fake fix) |
| 5 | `select('id AS lead_id')` keeps its alias key | PASS |
| 5 | bare `orderBy('name')`, joined `where`, `join`+`select` all still work | PASS |

Transaction semantics were proved with a `wpdb` subclass recording control statements —
**`FakeWpdb` has no transaction support at all** (it throws on `START TRANSACTION`), which is
why the nested-transaction defect was never caught by the suite. That gap is recorded as an
open item, not fixed: it is out of scope.

**Suite:** `1472 tests, 2972 assertions, 25 errors, 1 failure` — **identical to the baseline
recorded in `PROJECT-LOG.md`** for the query-builder-upgrade step. All 26 are the pre-existing
`LeadEntityTest` breakage from the 2026-11-08 shared swap (destroyed rich entity). Zero new
failures.

## Completion checklist (audited against the actual code)

- [x] Mode named before coding (JOB F); the `framework/` edit is plan-authorized
- [x] No `App\` / `Core\` / project / table name in `framework/Database/Query/` (grep: clean)
- [x] No `env()` outside `config/` (grep: clean)
- [x] No global helper call
- [x] No dead code; no commented-out code
- [x] `final` class preserved; constructor property promotion preserved
- [x] Interface and implementation signatures agree
- [x] Semantic change documented (this report); no Laravel name reused with different meaning
- [x] `tests/` change recorded as a decision, not made silently
- [x] Evidence produced by execution, not assertion

## Open items carried forward

- **`FakeWpdb` has no transaction support.** Every `START TRANSACTION` / `COMMIT` /
  `ROLLBACK` throws `LogicException`. The Phase 2 defect was invisible to the suite for this
  reason. Worth adding to the fake — out of this plan's scope.
- **`WpFluentQueryBuilder::writeFailure()`** still opens with an unused `$db = $this->db();`
  (intelephense P1003). Pre-existing; not touched.
- **`LeadEntityTest`** — 25 errors + 1 failure, pre-existing since 2026-11-08. Unchanged.
