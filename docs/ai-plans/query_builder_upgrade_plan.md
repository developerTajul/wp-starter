# Execution Plan: Query Builder Upgrade (Laravel Parity)

**Context & Goal:** 
Upgrade the custom framework-agnostic `QueryBuilderInterface` and `WpFluentQueryBuilder` to support advanced Read operations (`JOIN`, `LIKE`, `IN`, `OR`, `NULL` checks). This is strictly required to comply with `@agents.md` rules (eliminating raw `$wpdb` usage in Repositories) while maintaining the Immutable Builder pattern.

**Architectural Constraints (from `@agents.md`):**
- Strict typing (`declare(strict_types=1);`).
- The builder MUST remain immutable (every modifier returns a cloned instance).
- Bindings must be deferred to `$wpdb->prepare()` at execution time.
- No `illuminate/database` or ORM components are allowed.

---

## Phase 1: Interface & Docblock Refactoring
**File:** `framework/Database/Query/QueryBuilderInterface.php`

- [ ] **Remove Obsolete Constraints:** Delete the entire block comment titled *"What is deliberately absent: join() and orWhere()"*. The architectural decision has changed, and these are now strictly required.
- [ ] **Update Operator Support:** Modify the docblock for the `where()` method to explicitly include `LIKE` and `NOT LIKE` in the list of supported `$operator`s.
- [ ] **Add Missing Signatures:** Add the following method signatures to the interface with comprehensive docblocks explaining their immutable nature:
  ```php
  public function orWhere(string $column, string|int|float|bool $value, string $operator = '='): static;
  public function whereIn(string $column, array $values): static;
  public function whereNotIn(string $column, array $values): static;
  public function whereNull(string $column): static;
  public function whereNotNull(string $column): static;
  public function join(string $table, string $first, string $operator, string $second): static;
  public function leftJoin(string $table, string $first, string $operator, string $second): static;
  ```

## Phase 2: Implementation in WpFluentQueryBuilder
**File:** `framework/Database/Query/WpFluentQueryBuilder.php`

- [ ] **Update State Properties:** Introduce internal state arrays to hold `joins` and correctly structure `wheres` to track boolean connections (`AND` vs `OR`).
- [ ] **Implement `LIKE` Support:** Update the `where()` validation logic to allow `LIKE` and `NOT LIKE`. Ensure `%` wildcards are preserved and passed safely to `$wpdb->prepare()`.
- [ ] **Implement `orWhere()`:** Add the method. It must clone the builder, add the condition with an `OR` boolean flag, and return the new instance.
- [ ] **Implement `whereIn()` & `whereNotIn()`:** Accept an array of values. Ensure the compiler generates the correct number of `%s` or `%d` placeholders (e.g., `IN (%d, %d, %d)`) and merges the values into the deferred bindings array.
- [ ] **Implement `whereNull()` & `whereNotNull()`:** Generate `IS NULL` and `IS NOT NULL` SQL segments. These do not require parameter bindings.
- [ ] **Implement `join()` & `leftJoin()`:** Add methods to store table joins. Update the `SELECT` query compiler logic to append `INNER JOIN` or `LEFT JOIN` segments right after the `FROM` clause and before `WHERE`.

## Phase 3: SQL Compiler Updates
**File:** `framework/Database/Query/WpFluentQueryBuilder.php`

- [ ] **Refactor `compileSelect()`:** 
  - Ensure the execution sequence respects the new components: `SELECT ... FROM ... [JOINS] [WHERES] [ORDER BY] [LIMIT] [OFFSET] [LOCK]`.
- [ ] **Refactor `compileWheres()`:**
  - Loop through the conditions and properly concatenate them using their designated boolean operators (`AND` / `OR`).
  - Correctly map the placeholders for `IN` arrays.

## Phase 4: Eradicate Raw $wpdb from Repositories
**Files:** `core/Company/Infrastructure/Repositories/WPFluentCompanyReadRepository.php` (and any other Read Repositories like Lead).

- [ ] **Refactor Read Methods:** Replace all raw `$wpdb` SQL strings with the newly upgraded Fluent Query Builder methods.
  - Convert `WHERE name LIKE %...% OR industry LIKE %...%` to `$builder->where(...)->orWhere(...)`.
  - Convert raw `JOIN` clauses to `$builder->join(...)`.
- [ ] **Verify strict_types:** Ensure data mapped from the updated queries still casts integers/strings correctly before passing to Entities (respecting `toNullableString` fixes).

## Phase 5: Testing & Verification
- [ ] **Unit Tests:** Update `WpFluentQueryBuilderTest.php` to assert the newly compiled SQL strings for joins, nested/OR wheres, and IN clauses.
- [ ] **Integration Check:** Trigger the Company controller read operations in the browser/API to verify that WordPress's `$wpdb` successfully executes the newly generated complex queries.