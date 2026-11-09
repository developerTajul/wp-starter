# Execution Plan: Query Builder Critical Bug Fixes

**Context & Goal:**
The `WpFluentQueryBuilder` and its interface require critical bug fixes to prevent SQL syntax errors during `JOIN` and `ORDER BY` operations, prevent data corruption during `UPDATE` with `NULL` or `bool` values, and fix a logical flaw in nested transaction handling. This plan adheres strictly to the `@agents.md` guidelines (strict typing, immutability, deferred bindings).

## Phase 1: Interface & Signature Alignment

**File:** `framework/Database/Query/QueryBuilderInterface.php`

* \[ \] **Fix Type Mismatch in `where()` and `orWhere()`:**
  Update the method signatures to explicitly include `null` in the `$value` union type: `string|int|float|bool|null`.
  *Reasoning:* PHP contravariance allows the implementation to widen the type, but aligning the contract prevents static analysis tools from complaining.

* \[ \] **Update Docblocks:** Modify the docblocks for `where()` and `orWhere()` to clarify that `null` is accepted *only* to throw a clear, descriptive `QueryException` immediately (to prevent silent `IS NULL` misinterpretations), rather than saying "Deliberately not nullable".

## Phase 2: Fix Nested Transaction Logic

**File:** `framework/Database/Query/WpFluentQueryBuilder.php`

* \[ \] **Refactor `beginTransaction()`:**
  Modify the logic to properly increment the depth counter on every call. Only execute the actual `START TRANSACTION` SQL when the depth transitions from `0` to `1`.
  *Implementation idea:* `if ($this->transactionDepth++ === 0) { $this->db()->query('START TRANSACTION'); }`

* \[ \] **Refactor `commit()`:**
  Modify the logic to decrement the depth counter. Only execute the actual `COMMIT` SQL when the depth reaches `0`. Ensure it does not decrement below `0`.
  *Implementation idea:* `if (--$this->transactionDepth === 0) { $this->db()->query('COMMIT'); }`

* \[ \] **Refactor `rollback()`:**
  Ensure it resets the depth to `0` and executes `ROLLBACK` only if the depth was greater than `0` before the reset.

## Phase 3: Fix Data Corruption in `UPDATE` (Null & Boolean handling)

**File:** `framework/Database/Query/WpFluentQueryBuilder.php`

* \[ \] **Refactor `compileAssignment()`:**
  Update the loop that builds the `SET` clause to properly inspect the `$value`:

  1. If `$value === null`: Append `` `column` = NULL `` directly to the assignment array (without a placeholder) and *do not* add it to the `$args` array.

  2. If `is_bool($value)`: Cast it to an integer (`$value ? 1 : 0`) before determining the placeholder, preventing it from being cast to an empty string by `$wpdb`.

  3. Otherwise, use `placeholderFor($value)` and append it to `$args` as usual.

## Phase 4: Fix Identifier Quoting in `ORDER BY`

**File:** `framework/Database/Query/WpFluentQueryBuilder.php`

* \[ \] **Refactor `compileSelect()` -> ORDER BY block:**
  Change the column quoting method used in the `ORDER BY` builder loop.
  Replace `self::quoteIdentifier($order['column'])` with `$this->quoteColumn($order['column'])`.
  *Reasoning:* `quoteIdentifier` improperly wraps dot-notated columns like `companies.id` into a single invalid identifier `` `companies.id` ``. `quoteColumn` correctly splits and quotes them as `` `companies`.`id` ``.

## Phase 5: Fix Table Prefixing in Joined Columns

**File:** `framework/Database/Query/WpFluentQueryBuilder.php`

* \[ \] **Change Method Scope:** Convert `quoteColumn()` and `quoteProjection()` from `static` to instance methods so they can access `$this->prefix()`.

* \[ \] **Refactor `quoteColumn()`:**
  Update the logic that handles dot-notated columns (`table.column`). When splitting the string by the dot, prepend `$this->prefix()` to the table portion before passing it to `quoteIdentifier()`.
  *Note:* Ensure you account for table aliases. If a table alias was used in the `JOIN` or `FROM` clause, it should not be prefixed. (You may need to maintain a simple array of applied aliases in the class state to differentiate between actual table names and aliases).

* \[ \] **Update Call Sites:** Update all internal calls from `self::quoteColumn(...)` and `self::quoteProjection(...)` to `$this->quoteColumn(...)` and `$this->quoteProjection(...)`.