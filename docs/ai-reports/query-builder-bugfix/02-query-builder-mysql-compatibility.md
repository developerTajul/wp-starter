# 02 — Query Builder MySQL Compatibility Fixes

**Plan:** `docs/ai-plans/query_builder_mysql_fix_plan.md` (all 3 phases)
**Mode:** JOB F (FRAMEWORK) — `framework/Database/Query/WpFluentQueryBuilder.php`.
**Date:** 2026-11-09
**Result:** DONE. The plan's diagnosis was **correct** and this step **reverses**
`D-2026-11-09-06` from the previous step.

## Correction of the previous step's decision

`D-2026-11-09-06` (previous step) decided **not** to prefix qualifiers, on the reasoning that
MySQL resolves a bare qualifier as the alias created by `FROM `wp_deals``. **That was wrong,
and it is now disproven by measurement.**

The previous step validated a claim about **MySQL** against **`FakeWpdb`**. The fake tolerates
bare qualifiers because `mergeJoinedRow()` keys a joined column under *both* `companies.name`
and `wp_companies.name` — a leniency real MySQL does not have. Reasoning from a test double
to the database it stands in for is the specific error, and it is the reason this plan looked
like a regression rather than a fix.

### Live MySQL 5.7.33 evidence (Laragon, `wp-lead-bridge` DB)

```
SELECT `deals`.`id`, `companies`.`name` FROM `wp_deals`
  INNER JOIN `wp_companies` ON `companies`.`id` = `deals`.`company_id`
ERROR 1054 (42S22): Unknown column 'deals.id' in 'field list'

SELECT `wp_deals`.`id`, `wp_companies`.`name` FROM `wp_deals`
  INNER JOIN `wp_companies` ON `wp_companies`.`id` = `wp_deals`.`company_id`
  ORDER BY `wp_companies`.`name` DESC;
id  name
2   Globex
1   Acme          -- returns rows
```

Side by side on one real table:

```
SELECT * FROM `wp_deals2` ORDER BY `wp_deals2`.`created_at` DESC;  -- rows
SELECT * FROM `wp_deals2` ORDER BY `deals2`.`created_at` DESC;     -- ERROR 1054
```

## What changed

### Phase 1 — table prefixing in qualified columns
- `quoteColumn()` now prepends `$this->prefix()` to the table portion of a dot-notated
  reference: `companies.name` → `` `wp_companies`.`name` ``.
- Docblock replaced. The old text claimed prefixing would break aliases; the new text records
  the MySQL evidence and the reason prefixing is unconditionally safe here: `table()` and
  `join()` take a bare name, apply the prefix themselves, and **never accept an `AS` alias**
  — so a qualifier can only ever be a real table name.

**Scope note.** The change is a single method, but it reaches every caller of `quoteColumn()`:
`where()`, `orWhere()`, `whereNull()`, `whereNotNull()`, `whereIn()`, `whereNotIn()`,
`join()`/`leftJoin()` (both sides of `ON`), `orderBy()`/`orderByDesc()`, `select()`, and
`compileAssignment()`. All of them are now internally consistent — verified by the emitted SQL
below.

**Column aliases are untouched.** `quoteProjection()` sends only `$reference` through
`quoteColumn()`; the alias stays on `quoteIdentifier()`, so `select('companies.name AS company')`
still emits `` `wp_companies`.`name` AS `company` ``.

### Phase 2 — boolean casting in `insertGetId()`
- Values are mapped through `boundValue()` before `$db->insert()`.
- The format list is derived from the **normalized** array, not the original — the plan's
  blueprint mapped formats from `$values` and data from `$safeValues`. `formatFor()` returns
  `%d` for a bool either way so it happened to be harmless, but deriving both from one array
  means the two lists cannot drift apart.

**Why the cast is genuinely needed.** Verified against real WordPress core
(`wp-includes/class-wpdb.php`), not the fake:

1. `insert()` → `_insert_replace_helper()` builds `INSERT INTO t (...) VALUES (%d)` and calls
   `$this->prepare($sql, $values)` with the raw value.
2. For a `%d` placeholder, `prepare()` pushes the argument through
   `_real_escape()` → `mysqli_real_escape_string($dbh, false)` → **`''`**.
3. `vsprintf('... VALUES (%d)', [''])` formats the empty string, not the boolean.

Current WordPress happens to land on `0`, but only through that lossy round-trip — and
`_real_escape()` returns `''` with a `_doing_it_wrong()` notice for any non-scalar. Casting
first makes the escaped value the intended value. **The plan's reasoning was correct.**

### Phase 3 — test harness alignment
**No further change was needed.** The previous step's `FakeWpdb::parseOrderBy()` fix
(`normalizeIdentifier()`) already makes the fake parse the prefixed form, and
`mergeJoinedRow()` already keys joined rows under both spellings. Verified directly:

```
normalizeIdentifier('wp_companies`.`name')  -> wp_companies.name
normalizeIdentifier('wp_deals`.`company_id')-> wp_deals.company_id
```

A prefixed join + qualified `WHERE` + qualified `ORDER BY` returns the correct row through the
fake (`["Ada"]`). The plan's "Run Full Suite" item is covered in Verification.

## Verification (evidence, not claims)

| Check | Result |
| --- | --- |
| Plan's premise reproduced on live MySQL 5.7.33 | CONFIRMED — bare qualifier = error 1054 |
| Prefixed form on live MySQL | CONFIRMED — returns rows |
| Exact SQL the builder emits (join + `OR` group + `DESC` + `LIMIT`) on live MySQL | EXECUTES, returns `Ada / Acme` |
| Real call sites compile to prefixed qualifiers | PASS — all 4 from `WPFluentCompanyReadRepository` |
| Column alias `AS `company`` not prefixed | PASS |
| `FakeWpdb` normalizes prefixed qualifiers | PASS |
| `insertGetId()` bool path | casts before `$db->insert()`; formats derived from the same array |
| Full suite | `1472 / 2972 / 25 errors / 1 failure` — **identical to baseline, zero new failures** |

Emitted SQL after the change:

```sql
SELECT `wp_leads`.`name`, `wp_companies`.`name` AS `company` FROM `wp_leads`
INNER JOIN `wp_companies` ON `wp_companies`.`name` = `wp_leads`.`company_name`
ORDER BY `wp_companies`.`name` DESC

SELECT * FROM `wp_company_contact` ORDER BY `wp_company_contact`.`is_primary` DESC
SELECT * FROM `wp_deals`           ORDER BY `wp_deals`.`created_at` DESC
```

## Completion checklist (audited against the actual code)

- [x] Mode named (JOB F); the `framework/` edit is plan-authorized
- [x] No `App\` / `Core\` / project / table name in the framework file
- [x] No `env()` outside `config/`; no global helper call
- [x] `final` class, constructor property promotion preserved
- [x] No dead code; no stale docblock left behind
- [x] Every plan checkbox executed or explicitly accounted for
- [x] Claims backed by execution (live MySQL + fake), not by reading the diff
- [x] Probe database `qb_probe` dropped; scratch directories removed

## Open items carried forward

- **`FakeWpdb` accepted bare qualifiers and hid a production-breaking bug.** Its leniency
  (`mergeJoinedRow()` keying rows under two spellings) let invalid SQL pass. Worth narrowing
  the fake so it rejects a qualifier that does not match the statement's own table — that is
  what would have caught this before it reached a live site.
- **`FakeWpdb` still has no transaction support** (unchanged from the previous step).
- **`LeadEntityTest` — 25 errors + 1 failure**, pre-existing since 2026-11-08. Unchanged.
- **`docs/ai-reports/DECISIONS.md` D-2026-11-09-06 is superseded** by this step. Its proposed
  plan amendment ("drop the prefixing requirement") is withdrawn.
