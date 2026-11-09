# Step 01 — Query Builder Upgrade (Laravel Parity) and Raw `$wpdb` Eradication

Plan: `docs/ai-plans/query_builder_upgrade_plan.md` (read-only, not edited).
Authorisation: the developer asked for the plan to be executed against `AGENTS.md`, then
"execute one by one, no confirmation needed" for all five phases.

## MODES NAMED BEFORE CODING

| Zone | Paths touched | Mode | Rules loaded |
| --- | --- | --- | --- |
| `framework/` | `Database/Query/QueryBuilderInterface.php`, `Database/Query/WpFluentQueryBuilder.php` | FRAMEWORK (JOB F) | 10, 02 |
| `core/*/Infrastructure` | `Company/.../WPFluentCompanyReadRepository.php`, `Lead/.../LeadReadRepository.php` | ADAPTER | 02, 06 |
| `app/` | `Providers/CompanyServiceProvider.php`, `Providers/RepositoryServiceProvider.php` | DELIVERY | 02 |
| `tests/` | `Support/FakeWpdb.php`, `Unit/Database/Query/WpFluentQueryBuilderTest.php`, `Integration/Database/QueryBuilderExecutionTest.php` | test harness | — (testing explicitly requested by the plan's Phase 5) |

`framework/` was edited as a JOB F task because the plan names those two files as its
Phases 1-3 deliverables. No `App\` or `Core\` symbol was introduced into `framework/`.
No FROZEN folder (`Domain/`, `Application/`) was edited: the two repositories changed are
both `Infrastructure/`, which AGENTS.md marks REPLACEABLE.

## PHASE-BY-PHASE RESULT

### Phase 1 — Interface and docblocks (DONE)

`framework/Database/Query/QueryBuilderInterface.php`

- The "What is deliberately absent: `join()` and `orWhere()`" block is gone, replaced by
  "The read surface is a working vocabulary, not a subset".
- `where()`'s docblock now lists `LIKE`/`NOT LIKE` in the closed operator set, and states
  the two rules a caller needs: the caller owns wildcard placement, and a term that
  arrived from a user is escaped **before** the wildcards are added.
- Added: `orWhere()`, `whereGroup()`, `whereIn()`, `whereNotIn()`, `whereNull()`,
  `whereNotNull()`, `join()`, `leftJoin()`.

`whereGroup()` is **beyond the plan's signature list** and is the one addition the plan
did not ask for. It is required by the plan's own Phase 4: the Company search is
`name LIKE ? OR industry LIKE ?` and must be bracketed, or its `OR` leaks into any later
conjunction. Recorded in `DECISIONS.md`.

### Phase 2 — Builder implementation (DONE)

`framework/Database/Query/WpFluentQueryBuilder.php`

- `OPERATORS` extended to `['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE']`,
  matched case-insensitively and emitted spelled as the constant holds it.
- `JOIN_TYPES` map: `join` => `INNER JOIN`, `leftJoin` => `LEFT JOIN`.
- `$wheres` reshaped to `list<array{boolean, fragment, args}>` so a clause carries its own
  connective and its own bindings; `$joins` added.
- New internals: `operator()`, `addWhere()`, `addJoin()`, `compileComparison()`,
  `inFragment()`, `compileJoins()`, `splitAlias()`, `quoteColumn()`, `quoteProjection()`.
- Immutability preserved: every modifier returns `clone $this`. Verified by the existing
  `test_chaining_does_not_mutate_the_instance_it_started_from`, which runs two chains off
  one base and reads rows back.
- Bindings stay deferred: values are stored with `%s`/`%d`/`%f` and reach
  `$wpdb->prepare()` once, at execution. Identifiers (column, table, operator, direction,
  join type) are validated against closed lists, never bound.

### Phase 3 — SQL compiler (DONE)

- `compileSelect()` emits `SELECT … FROM … [JOINS] [WHERE] [ORDER BY] [LIMIT] [OFFSET] [LOCK]`.
- `compileWheres()` concatenates each clause with its stored boolean; a group compiles as
  one bracketed fragment, so `a AND (b OR c)` is expressible.
- `column()` validator extended from a single identifier to
  `identifier [AS identifier]`, because a joined read needs aliases (two tables can both
  carry `id`). `quoteProjection()` emits `` `t`.`c` AS `alias` ``; `quoteColumn()` splits
  `table.column` and quotes each part.

### Phase 4 — Raw `$wpdb` out of the repositories (DONE, scope stated)

**`core/Company/Infrastructure/Repositories/WPFluentCompanyReadRepository.php`** — the
file the plan names. Removed `use wpdb;`, the `?wpdb` constructor parameter,
`OUTPUT_ASSOC`, `searchCompanies()`, `results()`, `columnList()`, `table()`, `quote()`,
`escapeIdentifier()`, `db()`, `queryFailed()`. Added table-suffix constants, `baseQuery()`
(shared by the count and the page so the two cannot disagree), `escapeLike()`. Reads now
use `join()`, `leftJoin()`, `whereGroup()`, `whereIn()`, and aliased projections.

**`core/Lead/Infrastructure/Repositories/LeadReadRepository.php`** — the "any other Read
Repositories like Lead" the plan names. Rewritten onto the builder. `exists()` is now a
`count()` on a predicate; `findRow()` takes a builder-mutating callable and calls
`first()`. `db()` is retained **solely** for `getTableName()`, which reads `$wpdb->prefix`
— D6 forbids raw SQL in repositories, not reading the installation prefix, and the prefix
is not obtainable through the builder.

`grep` over both files returns **no raw SQL** and no `$wpdb` use other than that prefix
read. `WpFluentCompanyReadRepository` has zero `$wpdb` references at all.

### Phase 5 — Testing and verification (DONE; the integration item is stated honestly)

**Unit** — `tests/Unit/Database/Query/WpFluentQueryBuilderTest.php`: `LIKE` moved from the
"unsupported operator" example to a supported one (`REGEXP` now stands in its place as a
real MySQL operator a builder should refuse), plus two tests pinning that `LIKE` is
accepted case-insensitively and that the builder adds no wildcards of its own.

**Integration** — `tests/Integration/Database/QueryBuilderExecutionTest.php`: 8 execution
tests for the new vocabulary, then 6 more for joins once the harness could run them:
inner join, left join with a null-filled column, `AS` alias keying, a condition reaching
the joined table, the full joined-read-with-group-and-page shape, and a join matching
nothing.

**The harness had to be taught the new grammar first.** `FakeWpdb` threw
`LogicException` on every `JOIN`, refused qualified/aliased projections, and escaped only
the grammar that existed before this change. Writing tests against it unchanged would have
asserted nothing — the failure mode memory records as "a green test over a path nothing
executes reads as coverage it does not provide". `tests/Support/FakeWpdb.php` gained join
parsing (`applyJoin()`, `joinValuesMatch()`, `mergeJoinedRow()`, `prefixed()`,
`unprefixed()`, `normalizeIdentifier()`), qualified/aliased projections, and a shared
`compare()`.

**Executed, not just inspected.** The real `WPFluentCompanyReadRepository` was run end to
end against the fake with a realistic schema, exercising all three public reads. Observed
output:

```
searched list ('Acme' across name and industry): total=2
  Globex / Acme Manufacturing
  Acme Analytics / Software

detail: company=Acme Analytics
  contacts: 1 — Ada Lovelace <ada@example.test> primary=true
  deals: 2 — Orphan deal 250.00 USD stage=NULL / Acme deal 1000.00 USD stage=Won
    deal 100 activity "Kickoff" user='Grace Hopper'
    deal 100 activity "Author deleted" user=NULL
  company activities: 1 — "Company note" user='Grace Hopper'
```

That single run proves the grouped search, the inner join (contacts), the left join
(stage absent => null, author deleted => null), the aliases (`stage_id`, `user_name`) and
`whereIn()` over the deal ids.

**The plan's "Integration Check" item is NOT done as written.** It asks for the Company
controller read operations to be triggered in a browser or against the API. No WordPress
install was driven. The check was performed against the fake with the real repository, the
real builder and the real mapper — which executes the generated SQL through a parser, not
through MySQL. Reported as verified-by-execution-against-a-double, not as verified in a
running site.

## DEFECTS FOUND WHILE DOING THIS (in the harness, all fixed)

Each of these was found because a test was written to execute the statement rather than
compare its string, and each would have made a broken builder look correct:

1. `??` used to resolve a projected column. A left-joined column whose row is absent is
   legitimately `null`, and `??` read that null as "key missing" and fell through to a
   same-named column of the **other** table — returning a real value from the wrong table.
   Now resolved by `array_key_exists()` first.
2. `mergeJoinedRow()` keyed joined columns under the prefixed table name only, while a
   projection spells the table bare. Both spellings are now written.
3. `trim($s, " \`")` on a qualified identifier leaves the inner backticks
   (`` `companies`.`id` `` became `` companies`.`id ``), so every qualified projection
   missed and fell back. Replaced by `normalizeIdentifier()`, which unquotes each part.
4. `prefixed()` did not exist: `applyJoin()` looked up `$this->tables['companies']` while
   the store keys `wp_companies`, so **no join ever matched anything** and every joined
   read silently returned the null-filled outer-join row.
5. `qualifiedName()` dropped the table qualifier, so `companies.name LIKE '%Acme%'`
   compared the **left** table's `name`. The qualifier is now kept, and the FROM table's
   own columns are also written under their qualified names so a predicate against the
   FROM table still resolves.

Defect 4 is worth calling out: the first version of the join support **passed the test
that asserted an inner join returns rows** in a two-row fixture where the null-filled left
row happened to look like a match. It only surfaced when a test asserted the *value* of a
joined column.

## ALSO FOUND, REPORTED NOT CHANGED (out of scope)

Six `$wpdb`-based repository classes remain in `core/Lead/Infrastructure/Repositories/`:
`LeadTimelineReadRepository`, `LeadDealReadRepository`, `LeadNoteReadRepository`,
`LeadNoteWriteRepository`, `LeadConversionWriteRepository`, `LeadOwnerFormOptionsRepository`.

None is bound in any provider, referenced by any controller, route, service or test
(`grep` over `app/`, `routes/`, `config/`, `tests/` returns nothing). Their contracts
(`LeadTimelineContract`, `LeadDealContract`, `LeadNoteQueryContract`,
`LeadConversionRepositoryContract` and the matching `*QueryServiceContract`s) exist and are
referenced only by the orphaned services beside them.

They are **left untouched**. The plan names the Company repository and "any other Read
Repositories like Lead", which the two files it lists satisfy; converting six unwired
classes with no callers and no tests is not the step the plan describes, and rewriting dead
code would produce a large untested diff that no read path exercises. Flagged for a
separate decision.

Also noted, pre-existing and not introduced here: `writeFailure()` in
`WpFluentQueryBuilder` opens with an unused `$db = $this->db();` (intelephense P1003). Left
alone as outside the plan's scope.

## SUITE RESULT

```
Tests: 1472, Assertions: 2972, Errors: 25, Failures: 1.
```

Before this step: 1466 / 2962 / 25 / 1. The 6 extra tests are the new join tests; the new
assertions are theirs.

**Every one of the 26 is `Tests\Unit\Core\Lead\LeadEntityTest`.** Confirmed by grouping
the failure headers by class, not by reading the list by eye:

```
     26 Tests\Unit\Core\Lead\LeadEntityTest
```

This is the pre-existing breakage recorded in memory as the 2026-11-08 shared swap that
replaced the rich `LeadEntity` (`reconstitute()`, `limits()`, the `LeadStatus` enum
signature, `$now` — all gone). It is parked and must not be patched by editing Domain to
make tests pass. **Zero errors or failures come from this step**, and the query-builder
suites are green: `WpFluentQueryBuilderTest` 31/31, `QueryBuilderExecutionTest` 45 tests
in the combined filter run, `OK (45 tests, 74 assertions)`.

## FILES CHANGED

| File | Change |
| --- | --- |
| `framework/Database/Query/QueryBuilderInterface.php` | obsolete absence block removed; 8 signatures added; `where()` docblock documents `LIKE` |
| `framework/Database/Query/WpFluentQueryBuilder.php` | operators, join types, wheres/joins state, new internals, `compileSelect()`/`compileWheres()` rewritten, identifiers accept aliases |
| `core/Company/Infrastructure/Repositories/WPFluentCompanyReadRepository.php` | raw `$wpdb` removed; all reads on the builder |
| `core/Lead/Infrastructure/Repositories/LeadReadRepository.php` | raw `$wpdb` removed; all reads on the builder |
| `app/Providers/RepositoryServiceProvider.php` | `LeadReadRepository` bound with the builder and mapper |
| `app/Providers/CompanyServiceProvider.php` | one stale comment corrected (the binding already matched the new constructor) |
| `tests/Support/FakeWpdb.php` | joins, qualified/aliased projections, prefix-aware lookups, shared comparison |
| `tests/Unit/Database/Query/WpFluentQueryBuilderTest.php` | `LIKE` supported; `REGEXP` as the refusal example; 2 new tests |
| `tests/Integration/Database/QueryBuilderExecutionTest.php` | 14 new execution tests |

All changed files pass `php -l`.

## COMPLETION CHECKLIST (audited against the code, not from memory)

- [x] Task modes named before coding; no `App\`/`Core\` symbol entered `framework/`; no
      FROZEN folder edited (both repositories are `Infrastructure/`).
- [x] No framework import in Core Domain/Application — nothing in those folders was
      touched at all.
- [x] No `env()` outside `config/`.
- [x] No business rule moved into a Service or an adapter; the repositories only translate
      between the builder and read models.
- [x] Repositories use **only** `QueryBuilderInterface` (D6); no raw SQL, no `$wpdb` query
      call. The one `$wpdb` use left in `LeadReadRepository` reads `prefix` for the table
      name, which is not a query.
- [x] Every database value is a bound parameter; every identifier is validated against a
      closed list (02 section 7).
- [x] Every new builder method is on the interface **and** the implementation, in the same
      step (rule 10, method parity).
- [x] Immutability preserved — every modifier clones.
- [x] Bindings deferred to execution-time `prepare()`.
- [x] File existence verified before every file was created or edited; no duplicate class,
      port or mapper was introduced.
- [x] No test, migration, package install or git operation was run beyond the plan's
      Phase 5 test item.
