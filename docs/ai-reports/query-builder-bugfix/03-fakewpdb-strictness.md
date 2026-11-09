# 03 — FakeWpdb Strictness Upgrade

**Plan:** `docs/ai-plans/fakewpdb_strictness_upgrade_plan.md` (all 3 phases)
**Mode:** `tests/` (test harness) — no `framework/`, `app/` or `core/` file touched.
**Date:** 2026-11-09
**Result:** DONE. The fake now refuses a qualifier the statement's own `FROM`/`JOIN`
never bound, with a clause-named error 1054 message, **independently of the result set**.
Five tests added that pin it, all mutation-verified. Suite 1477/2981/25 errors/1 failure —
baseline, zero new failures.

## Phase 1 — dual-key leniency eradicated

`mergeJoinedRow()` keys a joined column under the **prefixed** name only. The bare
`companies.name` alias is gone, along with the docblock that justified it.

`SELECT *` no longer leaks the fake's internal `table.column` keys to the caller
(`withoutQualifiedKeys()`), because a real server cannot return a column of that shape.

## Phase 2 — qualifier validation, and the three defects found doing it

### 2a. The scope comparison was itself lenient (the D-2026-11-09-06 error, repeated)

The first implementation compared `prefixed($qualifier)` against a scope of prefixed
table names — which resolves `deals` to `wp_deals` and **accepts** a bare qualifier.
That is precisely the mapping that let the production prefixing bug pass this fake.

Measured on live MySQL 5.7.33 (`fk_qual` scratch DB, since dropped):

```
SELECT `wp_deals`.`id` FROM `wp_deals`;              -- rows
SELECT `deals`.`id`    FROM `wp_deals`;              -- ERROR 1054 (42S22)
SELECT id              FROM `wp_deals`;              -- rows
SELECT `d`.`id`        FROM `wp_deals` AS `d`;       -- rows
SELECT `deals`.`id`    FROM `wp_deals` AS `deals`;   -- rows (only because of the AS)
```

MySQL accepts a qualifier only when it is the name its own `FROM`/`JOIN` bound. The
comparison is now **literal**: qualifier as written vs. table names as written, no
`prefixed()` applied to either side.

### 2b. Validation was row-dependent

Qualifier checks lived inside the projection's per-row mapper. `array_map` over an empty
row set runs zero iterations, so a statement whose `WHERE` matched nothing was never
validated at all:

```
SELECT `payments`.`name` FROM `wp_leads` INNER JOIN … WHERE `status` = 'gone'
   -> ACCEPTED []     (wrong: MySQL raises 1054 at prepare time)
```

MySQL raises 1054 while preparing the statement, before it reads a row. The projection is
now validated **eagerly**, against `tablesInScopeFor()` — the statement's declared tables,
read from `FROM`/`JOIN` without evaluating any clause — before `selectRows()` is called.

### 2c. A correct query silently returned nothing

`qualifiedName()` returned the qualifier **as written** (`leads.name`) while the row store
keyed it **prefixed** (`wp_leads.name`). The predicate then read `null` for every row. A
legal joined read answered "no rows" — a wrong answer that looks like a right one, and
worse than a loud failure. Fixed by resolving to the spelling the check accepted.

## Phase 3 — suite audit

**No test was broken by the strictness, and none needed its raw SQL updating.** The plan
predicted otherwise. The reason is worth recording: every qualified read in the suite goes
through the builder, which prefixes a qualifier before composing the statement, so the
fake never saw a mismatched one.

That is also why the strictness was **unverified by the suite** — confirmed by mutation:
re-applying the leniency left all 1472 tests at baseline. Five tests were therefore added
to `QueryBuilderExecutionTest`, feeding the fake statements the builder would not compose:

| Test | Pins |
| --- | --- |
| `test_a_bare_qualifier_that_the_from_clause_never_bound_is_refused` | 2a — the exact production bug |
| `test_a_qualifier_naming_a_table_outside_the_statement_is_refused` | per-statement name resolution |
| `test_an_unknown_column_is_refused_even_when_no_row_matches` | 2b — eager, not row-dependent |
| `test_an_unknown_qualifier_in_a_where_clause_names_the_where_clause` | the message names the clause |
| `test_the_prefixed_qualifier_resolves_to_the_row` | tightening does not refuse valid SQL |

**Mutation-verified as load-bearing** (each mutation reverted afterwards):

| Mutation | Result |
| --- | --- |
| Re-prefix the qualifier in `assertQualifierIsInScope()` (the D-06 leniency) | **1 test fails** |
| Make projection validation row-dependent again | **3 tests fail** |

## Verification (evidence, not claims)

| Check | Result |
| --- | --- |
| Live MySQL 5.7.33 — bare qualifier vs. prefixed table | CONFIRMED error 1054; prefixed + unqualified + real alias return rows |
| The original production bug SQL, fed to the fake | **REJECTED**: `Unknown column 'leads' in 'field list'` |
| The corrected builder SQL, fed to the fake | ACCEPTED, returns the row |
| Unknown qualifier in `SELECT` / `WHERE` / `ORDER BY` | REJECTED, each naming its own clause |
| Unknown qualifier with a `WHERE` matching no row | REJECTED (eager) |
| Full suite | 1477 / 2981 / 25 errors / 1 failure — **baseline, zero new failures** |
| Failures outside `LeadEntityTest` | none |
| Scratch dir `.fk-probe/` removed; DBs `fk_qual`, `qb_probe`, `fk_probe` dropped | confirmed |

## Completion checklist (audited against the actual code)

- [x] Mode named (`tests/` harness); no `framework/`, `app/` or `core/` file touched
- [x] `declare(strict_types=1)` intact; typed properties and signatures kept
- [x] Docblocks record the enforced strictness and the live-MySQL evidence
- [x] Dead code and stale docblocks removed (the `mergeJoinedRow()` justification is gone)
- [x] Every plan checkbox executed or explicitly accounted for
- [x] Claims backed by execution, not by reading the diff
- [x] Each new test mutation-verified to fail when its mechanism is reverted

## Open items carried forward

- **The fake has no table-alias support.** `FROM `wp_leads` AS `leads`` is not parsed, so
  the alias is not in scope and a legal aliased statement is refused. No builder call site
  emits a table alias (`table()`/`join()` always write the prefixed name), so nothing in
  the suite needs it — but a future aliased read would hit it. Not added: out of the plan's
  scope, and a capability nothing exercises cannot be verified. A test asserting it was
  written and then **removed** rather than left passing for the wrong reason.
- **`FakeWpdb` still has no transaction support** (unchanged). This is why the nested-
  transaction defect in `WpFluentQueryBuilder::beginTransaction()` was invisible to the
  suite. Still the largest remaining gap in the harness.
- **`LeadEntityTest` — 25 errors + 1 failure**, pre-existing since 2026-11-08. Unchanged;
  do not patch Domain to satisfy them.
- **A `WHERE` the fake cannot evaluate filters to nothing, silently.** `compileEqualityPredicate`
  understands equality, `IN`, `IS NULL` and `LIKE`; anything else empties the row set, which
  under 2b's fix is no longer a validation hole but is still a wrong answer. Worth a loud
  failure instead — out of this plan's scope.
