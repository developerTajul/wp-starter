# Execution Plan: Query Builder MySQL Compatibility Fixes

**Context & Goal:**
The `WpFluentQueryBuilder` currently generates invalid SQL for real MySQL environments when resolving qualified columns in `JOIN`, `SELECT`, or `WHERE` clauses (e.g., `deals.company_id` compiles to `` `deals`.`company_id` `` instead of `` `wp_deals`.`company_id` ``). This causes a fatal "Unknown column" error because the tables in the `FROM` and `JOIN` clauses are properly prefixed, but the column references are not. Additionally, boolean values in `insertGetId()` bypass the safe casting applied in `update()`.

This plan outlines the steps to resolve these real-world MySQL execution bugs while strictly adhering to `@agents.md` (no external ORMs, strict typing, maintaining immutable states).

## Phase 1: Fix Table Prefixing in Qualified Columns

**File:** `framework/Database/Query/WpFluentQueryBuilder.php`

* [ ] **Refactor `quoteColumn()`:**
  Update the method to automatically prepend the WordPress table prefix to the table portion of a qualified column name. 
  *Logic details:* When splitting the string by the dot (`.`), apply `$this->prefix()` to the left side (the table name) before passing it to `self::quoteIdentifier()`.
  
  *Suggested Implementation Blueprint:*
  ```php
  private function quoteColumn(string $column): string
  {
      $dot = strpos($column, '.');

      if ($dot === false) {
          return self::quoteIdentifier($column);
      }

      $table = $this->prefix() . substr($column, 0, $dot);
      $col = substr($column, $dot + 1);

      return self::quoteIdentifier($table) . '.' . self::quoteIdentifier($col);
  }
  ```
* [ ] **Update Docblock for `quoteColumn()`:** 
  Remove the outdated rationale that claimed prefixing here would break aliases. Document that since `table()` and `join()` do not support custom table aliases, qualified columns *must* be prefixed to match the actual table names used in `FROM` and `JOIN`.

## Phase 2: Safe Boolean Casting in `insertGetId()`

**File:** `framework/Database/Query/WpFluentQueryBuilder.php`

* [ ] **Refactor `insertGetId(array $values)`:**
  Before passing `$values` to `$db->insert(...)`, ensure all values are mapped through the existing `$this->boundValue()` method.
  *Reasoning:* `$wpdb->insert()` relies on `$wpdb->prepare()` internally. If a `bool(false)` is passed without being cast to `0`, `$wpdb` may cast it to an empty string, causing strict-mode MySQL to reject the insert or corrupt data.
  
  *Suggested logic update:*
  ```php
  $safeValues = array_map([$this, 'boundValue'], $values);
  $ok = $db->insert(
      $this->prefix() . $table,
      $safeValues,
      array_map([$this, 'formatFor'], array_values($values))
  );
  ```

## Phase 3: Test Harness Alignment (FakeWpdb)

**File:** `tests/Support/FakeWpdb.php` (or relevant test doubles)

* [ ] **Update SQL Parsers to Expect Prefixes:**
  Since `quoteColumn()` will now emit fully prefixed table qualifiers (e.g., `` `wp_companies`.`id` ``), ensure that the mock database engine (`FakeWpdb`) correctly parses, strips, or matches these prefixed identifiers during test assertions. 
* [ ] **Run Full Suite:**
  Execute the test suite to ensure the previously passing tests (like `test_a_joined_read_with_a_search_group_and_a_page_window`) continue to pass for the *right* reasons, matching the updated fully-qualified syntax.