# Execution Plan: Enforcing MySQL Strictness in FakeWpdb

**Context & Goal:**
The test harness (`tests/Support/FakeWpdb.php`) is currently too lenient. It merges joined rows under both bare and prefixed table keys, allowing unprefixed or mismatched table qualifiers to pass tests despite being invalid in real MySQL (Error 1054). 
The goal is to tighten `FakeWpdb` so that it strictly simulates MySQL behavior: it must reject queries with column qualifiers that do not exactly match the tables (or aliases) declared in the `FROM` and `JOIN` clauses.

**Architectural Constraints (from `@agents.md`):**
* Maintain strict typing (`declare(strict_types=1);`).
* Update docblocks to document the enforced strictness.
* Ensure tests validate real-world conditions without relying on external databases.

## Phase 1: Eradicate Lenient Column Resolution

**File:** `tests/Support/FakeWpdb.php`

* [ ] **Locate Row Merging Logic:** Find the method responsible for structuring returned rows from joins (e.g., `mergeJoinedRow` or similar internal array formatting).
* [ ] **Remove Dual-Key Mapping:** Stop mapping columns under both `companies.name` and `wp_companies.name`. The mock database must only recognize the exact table names as they were registered/prefixed in the mock schema or `FROM` clause.
* [ ] **Update Docblocks:** Remove any comments justifying the leniency. Add a clear docblock explaining that the fake strictly enforces exact qualifier matching to simulate MySQL Error 1054.

## Phase 2: Enforce Strict Qualifier Validation

**File:** `tests/Support/FakeWpdb.php`

* [ ] **Refactor SQL Parser for Columns:** When the fake parses `SELECT`, `WHERE`, or `ORDER BY` clauses, extract the table qualifiers from the columns (e.g., extracting `wp_deals` from `` `wp_deals`.`id` ``).
* [ ] **Validate Against Context:** Cross-reference these extracted qualifiers against the tables parsed from the `FROM` and `JOIN` clauses of that specific statement.
* [ ] **Simulate MySQL Error 1054:** If a query references a qualifier that is NOT in the active `FROM`/`JOIN` list, the fake MUST throw an exception (or log an error string and return `null`, depending on `wpdb`'s exact error simulation) stating: `Unknown column '...' in 'field list/on clause/where clause'`.

## Phase 3: Test Suite Audit & Remediation

* [ ] **Run the Full Test Suite:** Execute the 1472+ tests. 
* [ ] **Fix Broken Tests:** Tightening the fake will likely expose existing tests (possibly in the `Lead` module or older legacy tests) that were passing invalid raw `$wpdb` SQL.
* [ ] **Update Raw SQL:** For any failing tests caused by this new strictness, update the raw SQL strings in their respective Repositories or Test classes to use correctly prefixed table names (e.g., `wp_leads` instead of `leads` when qualifying columns manually).