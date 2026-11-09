# PROJECT LOG

One line per completed step, newest first. Detail lives in the step reports under
`docs/ai-reports/<module>/`. Decisions taken outside the rule files live in
`DECISIONS.md`.

| Date | Step | Report | Result |
| --- | --- | --- | --- |
| 2026-11-09 | FakeWpdb Strictness Upgrade — all 3 plan phases | [03](query-builder-bugfix/03-fakewpdb-strictness.md) | DONE. The fake now refuses a qualifier its own `FROM`/`JOIN` never bound, with a clause-named 1054, **eagerly** (not row-dependent). The first implementation repeated the D-2026-11-09-06 error — comparing `prefixed($qualifier)` — and was caught by live MySQL 5.7.33. Three defects fixed (2a literal comparison, 2b row-dependent validation, 2c a correct query silently returning nothing). Phase 3's predicted broken tests **did not exist**: every suite call site goes through the builder, so the strictness was unverified — proven by mutation. 5 tests added and mutation-verified as load-bearing. Suite 1477/2981/25 errors/1 failure — baseline, zero new failures. |
| 2026-11-09 | Query Builder MySQL Compatibility Fixes — all 3 plan phases | [02](query-builder-bugfix/02-query-builder-mysql-compatibility.md) | DONE. **Supersedes D-2026-11-09-06.** The plan's diagnosis was correct: bare qualifiers fail on real MySQL 5.7 with error 1054; prefixed qualifiers fixed and verified against a live server. Boolean cast added to `insertGetId()`. Suite 1472/2972/25 errors/1 failure — baseline, zero new failures. |
| 2026-11-09 | Query Builder Critical Bug Fixes — all 5 plan phases | [01](query-builder-bugfix/01-query-builder-critical-bug-fixes.md) | DONE. Phase 5's table prefixing deliberately not applied (plan's Phase 4 and Phase 5 conflict; resolved for Laravel parity + every existing call site — see D-2026-11-09-06). One required `tests/Support/FakeWpdb.php` fix (D-2026-11-09-07). Suite 1472/2972/25 errors/1 failure — identical to baseline. |
| 2026-11-09 | Query Builder Upgrade (Laravel parity) and raw `$wpdb` eradication from repositories — all 5 plan phases | [01](query-builder-upgrade/01-query-builder-parity-and-wpdb-eradication.md) | DONE. Suite 1472 tests / 2972 assertions / 25 errors / 1 failure — all 26 pre-existing in `LeadEntityTest` (the 2026-11-08 shared swap that destroyed the rich entity). Query-builder suites green. |

## Open items carried forward

- **CLOSED 2026-11-09 by report 03.** `FakeWpdb` tolerated bare qualifiers and hid a
  production-breaking bug: `mergeJoinedRow()` keyed a joined column under both
  `companies.name` and `wp_companies.name`, so invalid SQL (`ORDER BY `companies.name``
  after `FROM `wp_companies``) passed every test while failing on real MySQL with error
  1054 — which is how the prefixing bug reached a live site. The fake now compares a
  qualifier **literally** against the statement's own tables and refuses it otherwise,
  independently of the result set. Five tests pin it, mutation-verified.
  **Lesson kept:** the first attempt at this check repeated the original error by
  resolving `prefixed($qualifier)` — the same wrong reasoning, in the fix for it. A green
  suite is not evidence; only measurement against MySQL 5.7.33 settled it.
- **`FakeWpdb` has no transaction support.** `START TRANSACTION`, `COMMIT` and `ROLLBACK`
  all throw `LogicException` ("returning a plausible number would make a broken migrator
  look correct"). This is why the nested-transaction defect in
  `WpFluentQueryBuilder::beginTransaction()` was invisible to the suite for so long. Adding
  transaction state to the fake would let the Phase 2 fix be regression-tested.
- **`LeadEntityTest` — 25 errors + 1 failure, pre-existing.** `LeadEntity` lost
  `reconstitute()`, `limits()`, its `LeadStatus`-typed `$status` parameter and `$now`. Do
  not patch Domain to make the tests pass; the entity is the source of truth and the tests
  were written against a richer version. Tracked since 2026-11-08.
- **Six `$wpdb` repositories with no wiring and no callers** — `LeadTimelineReadRepository`,
  `LeadDealReadRepository`, `LeadNoteReadRepository`, `LeadNoteWriteRepository`,
  `LeadConversionWriteRepository`, `LeadOwnerFormOptionsRepository`. Deliberately left
  unconverted; see `DECISIONS.md` D-2026-11-09-05. Someone must decide whether they are
  wired or deleted.
- **The integration check in the query-builder plan was not run against a live site.** It
  was executed against the test double with the real repository, builder and mapper. See
  the step report's Phase 5 section.
- **`FakeWpdb` has no table-alias support.** `FROM `wp_leads` AS `leads`` is not parsed, so
  the alias is not in scope and a legal aliased statement is refused. No builder call site
  emits a table alias (`table()`/`join()` always write the prefixed name), so nothing in the
  suite needs it. Recorded because a future aliased read would hit it. See report 03.
- **A `WHERE` the fake cannot evaluate filters to nothing, silently.**
  `compileEqualityPredicate` understands equality, `IN`, `IS NULL` and `LIKE`; anything else
  empties the row set. No longer a validation hole (report 03 made validation eager), but
  still a wrong answer where a loud failure belongs. See report 03.
- **`WpFluentQueryBuilder::writeFailure()`** opens with an unused `$db = $this->db();`
  (intelephense P1003). Pre-existing, outside the plan's scope, left alone.
- **`docs/ai-reports/ROUTING-AUDIT-2026-11-09.md`** and
  **`docs/ai-reports/comment-cleanup/01-comment-cleanup.md`** record earlier findings with
  their own open lists, which are not repeated here.
