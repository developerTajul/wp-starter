# AGENTS.md (WordPress edition)
Binding rules for the AI. MUST/NEVER are absolute. Detail lives in `.claude/rules/` (index at end).

## PROJECT IDENTITY: TWO JOBS, THREE ZONES
This repository does two different jobs. They follow different rules and are NEVER mixed in one step.
- JOB F (FRAMEWORK): make the plugin behave like Laravel for WordPress developers (container, providers, config, routing, validation, migrations, console, exception handling). Lives in `framework/` (namespace `Framework\`) plus the Laravel-shaped conventions of `app/`. WordPress-aware by design. Future Packagist package. Rules: 02, 10.
- JOB C (CORE): build business modules in `core/` that are framework-free and portable to Laravel or any PHP framework. Rules: 01, 05, 06, 08 (+ 03, 04).

| Zone | Namespace | Knows WordPress? | May import | Who edits |
| --- | --- | --- | --- | --- |
| `core/*/Domain`, `core/*/Application`, `core/Shared/Domain`, `core/Shared/Application` | `Core\` | NEVER | PHP built-ins, PSR interfaces, other Core Domain/Application | JOB C. FROZEN during porting |
| `core/*/Infrastructure`, `core/Shared/Infrastructure` | `Core\` | YES (adapters) | Core contracts, `Framework\Database\` (Fluent Database), WordPress APIs for non-database adapters, PHP, PSR | JOB C. REPLACED during porting |
| `app/`, `config/`, `routes/`, `views/`, `database/` | `App\` | YES | Core contracts and DTOs, `Framework\` | project code (DELIVERY) |
| `framework/` (later `vendor/`) | `Framework\` | YES | PHP, WordPress, PSR, `devlinkstudios/wp-service-container` | JOB F ONLY. Read-only in every other task |

- `framework/` NEVER imports `App\` or `Core\`: it knows no project. `core/*/Infrastructure` NEVER imports `App\`, and imports from `Framework\` ONLY the Fluent Database public API (`Framework\Database\`). Config, helpers, HTTP, support classes and facades stay out (values arrive as constructor scalars).
- Rebranding prefixes `App\` and `Core\` (`<Vendor>\App\`, `<Vendor>\Core\`) and never flattens them. `Framework\` is never rebranded.

## MODE DETECTION (FIRST STEP OF EVERY TASK)
Before writing code, classify EVERY path the task touches, name the mode in the plan, load the listed rule files.

| Path | Mode | Load | Forbidden |
| --- | --- | --- | --- |
| `core/*/Domain`, `core/*/Application`, `core/Shared/(Domain\|Application)` | CORE-PURE | 01, 05, 06 (+08 events, 03 new module, 04 CRUD) | any WordPress, `Framework\`, `App\`, Laravel symbol; helper or facade call |
| `core/*/Infrastructure`, `core/Shared/Infrastructure` | ADAPTER | 02, 06 | business rules; `App\` imports; any `Framework\` import except `Framework\Database\`; Fluent Database or WP types leaking upward |
| `app/`, `routes/`, `config/`, `views/`, `database/` | DELIVERY | 02 | business rules; calling repositories from controllers; editing `framework/` |
| `framework/` | FRAMEWORK | 10, 02 | `App\`, `Core\`, project names, project config keys |

- A task spanning modes is split into ordered steps: CORE-PURE, then ADAPTER, then DELIVERY. A module task NEVER edits `framework/`.
- If a task needs a framework feature that is missing or must change: HALT (Interactive Alert), name the missing feature, wait. NEVER patch `framework/` or `vendor/` to unblock a module. In Autonomous Execution Mode this is a hard stop.
- Reading `framework/` or `vendor/devlinkstudios/**` to learn the API is always allowed. Editing is not.

## PORT FREEZE (ZERO-EDIT RULE)
FROZEN = `Domain/` and `Application/` of every module, plus `core/Shared/Domain` and `core/Shared/Application`. REPLACEABLE = every `Infrastructure/` folder.
- ONE source of truth per module: the project the developer names as the source. The other copy is a mirror. NEVER hand-edit a mirror's FROZEN folders; change the source, then re-port.
- Porting (Laravel or any PHP framework -> this plugin, or this plugin -> any framework): copy the FROZEN folders unchanged, write NEW Infrastructure adapters for the same contracts (`EloquentLeadWriteRepository` becomes `WpLeadWriteRepository`), write the target composition root and delivery. Procedure: 06 section T9.
- Porting NEVER edits a FROZEN file, not even one character. If it seems to need an edit, the design leaked a framework detail: add or tighten a port in the source, then re-port.
- ONE sanctioned mechanical difference: the vendor namespace prefix. WordPress runs every plugin in one PHP process, so this plugin's `Core\` is rebranded to `<Vendor>\Core\`. Only the prefix on `namespace` and `use` lines may differ, and only the rebrander or `tools/port-audit.sh` handles it, never a hand edit.
- PORT AUDIT (06 T8): with the prefix stripped, the FROZEN trees of source and mirror MUST be identical. A difference is a defect to report, never to silence.
- Business logic found in an adapter or delivery file while porting is lifted into the Entity in the SOURCE project first (P15), then re-ported. NEVER fix it only on the WordPress side.


## PROJECT DECISIONS (LOCKED BY THE DEVELOPER)
- D1 NAMESPACE PREFIX: the only allowed difference in FROZEN folders is the vendor prefix on `namespace`/`use` lines, applied by tool (rebrander, release-build prefixer), never by hand.
- D2 CONFIG INJECTION: the whole `Config` object is never injected into Core or Infrastructure. Contextual binding (`when()->needs()->give()`) passes only the needed scalar (string, int, bool). Only classes in `app/` may use `Config`.
- D3 PACKAGE RELEASE: at release build, `vendor/` and `framework/` (including `wp-service-container`) are prefixed (PHP-Scoper or a Mozart-family tool) so several plugins can ship the framework. Source keeps `Framework\`.
- D4 ADAPTER NAMING: every WordPress-specific Infrastructure class uses the `Wp` prefix (`WpNoteRepository`, `WpLeadWriteRepository`).
- D5 TRANSLATION: Core holds no user-facing string and never calls `__()`. Key-based, Laravel-style: `WpTranslator` (Infrastructure) reads `lang/<locale>/<module>.php`.
- D6 FLUENT DATABASE: Infrastructure repositories (`WpCompanyReadRepository`, `WpCompanyWriteRepository`) use ONLY `Framework\Database\Query\QueryBuilderInterface` (Laravel query-builder style). Raw SQL and `$wpdb` are forbidden in repositories. Transactions have ONE implementation: `QueryBuilderInterface::transaction()`; `WpTransactionManager` (Core Infrastructure) only delegates to it. A missing builder capability is a framework task, never a raw-SQL workaround. It NEVER enters Domain or Application.
- D7 GLOBAL HELPERS: code inside `framework/` (classes and logic) NEVER calls a global helper (`config()`, `app()`, `view()`, `base_path()` ...); it uses dependency injection or the container directly. Helpers exist only as project-level convenience for `app/`, `routes/`, `config/` and views, so several plugins running together cannot override or conflict. Core and Infrastructure never call them either.

## ARCHITECTURAL MANDATE
- Hexagonal (Ports and Adapters) + Clean Architecture + Rich Domain Model. Domain isolated from infrastructure/framework.
- `declare(strict_types=1);` in every file. `final` on all implementation classes (Services, Mappers, Adapters, Controllers). NOT on Entities.
- PRIMARY GOAL: `Core` folder moves unchanged to any PHP framework or a WordPress plugin. Porting = new Adapters + new composition root only. If porting forces a Domain/Application edit, the design is wrong: fix it at the port level.
- Dependencies point inward only:
  - Presentation Adapters (API/Web/Console/WordPress handlers) -> Application (Services) -> Domain (Entities, Value Objects, Domain Events, Ports).
  - Infrastructure Adapters (Fluent Database, WordPress, Queue, Events; Eloquent in the Laravel build) implement Ports declared in Core.
- ENV ONLY IN CONFIG (CRITICAL): NEVER call `env()` outside `config/*.php`. Environment variables are read once, at config load, inside `config/`. `config/*.php` is the single source of truth. Core never reads config: values reach Core and Infrastructure as constructor scalars/Value Objects (container contextual binding, 02 section 5); only `app/` code may take `Framework\Config\Config`.
- DEPENDENCY INJECTION: constructor injection through the container (`devlinkstudios/wp-service-container`). No service locator: `app()`, `resolve()`, `config()` are allowed only in the entry file, providers, routes, config, views and hook callbacks, NEVER inside Core, adapters or controllers.

## BEHAVIORAL CONSTRAINTS

### STRICT SCOPE ADHERENCE
- NEVER change code, logic, or files outside the prompt's explicit instructions. No assumptions, no unprompted fixes.
- If an out-of-scope change seems necessary (correctness, security, stability): HALT, state the exact reason, wait for explicit permission. In Autonomous Execution Mode apply 09 (A10) instead.
- FILE EXISTENCE GUARD (HIGHEST PRIORITY): before creating ANY file, verify it does not exist (list the directory or `git ls-files <path>`). A module is not new just because the part being built is new. If the file exists, editing it is OUT OF SCOPE: HALT unless the prompt names that file as something to change. A second file at an occupied path (duplicate Service, parallel Contract, forked Mapper) is the most damaging scope violation: it splits one port into two competing implementations.
- INHERENT WIRING (always in scope for the current step, no hard stop): binding a newly created Contract in `app/Providers/AppServiceProvider.php`; touching a module's own `app/Providers/<Module>ServiceProvider.php`; adding it to the `providers` list in `config/app.php`; registering routes for a newly created Controller action in `routes/api.php` / `routes/web.php`.
- `framework/` and `vendor/` are NEVER covered by wiring. A needed framework change is a separate JOB F task (see MODE DETECTION).
- These mandates do NOT authorize refactoring code the prompt did not ask you to touch. Apply them only to code you write or change.

### UNIT TESTING POLICY (TEMPORARILY PAUSED)
- NEVER write, generate, scaffold, or require unit/feature/integration tests. NEVER create files under `tests/`. NEVER run test suites unless the prompt asks.
- NEVER load or follow `07-testing.txt` while paused, unless the prompt asks for tests. Do not list or claim tests in any Definition of Done or checklist.
- An explicit prompt instruction ("write a test for X") overrides the pause for that request only.
- Architecture rules are unchanged. Keep code testable (ports behind `...Contract`, pure Entities, no framework in Core).
- Only the developer re-enables testing, explicitly. Then load 07 and remove this section.

### AUTONOMOUS EXECUTION MODE
Trigger: the developer asks you to execute a step, module, or plan. Load `09-autonomous-execution.txt` at the start of every such run.
- Plan lives in `docs/ai-plans/` (`00-MASTER-ROADMAP.md` = module order; each module folder = step briefs). Plan is read-only.
- Only the current step is in scope (wiring exception applies). Stop at the end of the requested range. "Continue the plan" with no range = finish the next unfinished module, then stop.
- After each step: audit the actual files (never from memory), write `docs/ai-reports/<module>/<NN>-<step-slug>.md`, update `docs/ai-reports/PROJECT-LOG.md`.
- NEVER offer the developer multiple choices. Decide by AGENTS.md and the rule files. If they do not cover it, decide as a framework-agnostic senior engineer: Core stays framework-free, depend on ports, smallest safe change, mirror database constraints, never invent business rules. Record each such decision in the step report and `docs/ai-reports/DECISIONS.md` with a proposed AGENTS.md change.
- HALT (Interactive Alert, then wait) ONLY for: missing required input; change outside step scope (except wiring); a change needed in `framework/` or `vendor/`; an edit that would touch a FROZEN folder (PORT FREEZE); destructive/irreversible action; security or secret handling; forbidden commands (migrations, package installs, test runs, git history changes); decision that breaks the architecture or needs a new technology; risky change to existing `core/Shared` artifacts; self-check still failing after two repair rounds; environment blocker persisting after two retries.
- Module end: write the module summary and `docs/ai-reports/NEXT-SESSION.md` (next module, session name, continue prompt). Start the next module in a new session only if the environment allows; otherwise stop and report the name and prompt.
- NEVER edit AGENTS.md, `.claude/rules/`, skill files, or the plan unless the step explicitly asks. Propose changes in the report.
- NEVER mark a step done without evidence. Report failed, partial, blocked steps honestly.

### ANTI-HALLUCINATION AND STRICT VERIFICATION PROTOCOL (CRITICAL)
- NEVER output a verification checklist without auditing the actual generated code. Every claim MUST match the code. False positives are forbidden.
- Cross-check actual diffs against claims. A feature being requested is not evidence it was implemented.
- PROCESS GUARD (MANDATORY): BEFORE coding, list the rule sections that apply. AFTER coding, re-read the finished code against each. Green tests are NOT evidence of rule conformance. Applies to every Autonomous step and every ad-hoc change.

## RICH DOMAIN MODEL MANDATE (SINGLE SOURCE OF TRUTH)
Business logic lives in Domain (Entities, Value Objects). Entities are the single source of truth for business rules.
- Entities expose behavior methods that state business intent (`$activity->publish()`, `$order->cancel()`), not generic setters.
- Invariants, state validation, and state transitions are enforced inside the Entity. An Entity MUST NOT be created or moved into an invalid state.
- Entities are `readonly class`, NEVER also `final` (`final` applies to Services, Mappers, Adapters, Controllers and other implementation classes). Never mutate in place: a behavior method enforces the rule and returns a NEW Entity. Delivery/Application/Infrastructure code MUST NOT write Entity properties; it calls the behavior method and uses the returned instance.
- Create through named constructors (`Activity::create(...)`); rebuild from storage via the Infrastructure EntityMapper.
- Fully encapsulated Entity: getters (`getId()`, `getTitle()`), private constructor, `create()` and `reconstitute()` as the only entry points. Encapsulation is per-module, not mandated; what IS mandated: nothing outside the Entity mutates its state.
- Repositories take and return Entities. NEVER pass DTOs or loose arrays from Service to Repository.
- Anemic entities (data bag + getters/setters) are forbidden.
- Services MUST NOT contain business rules, business-state conditionals, or calculations that belong to the Entity. A business-state decision in a Service becomes an Entity method.
- Entities, Value Objects, Domain Events MUST NOT import framework code (no `Illuminate\*`, WordPress functions/classes, ORM annotations).
- Eloquent models and WordPress post/table structures stay in Infrastructure, converted to/from Entities by a Mapper.
- Domain Events: held in a readonly list on the Entity. EVERY behavior method (even event-less) passes existing events to the NEW instance and appends new ones: `domainEvents: [...$this->domainEvents, new XEvent(...)]`. `releaseEvents()` only RETURNS the list, never clears it. The Command Service takes events from the Entity returned by the behavior method BEFORE persisting (`$events = $entity->releaseEvents(); $saved = $repository->update($entity);`). The repository-returned Entity has an empty list; NEVER release from it. The Service dispatches `$events` once via the EventDispatcher port after a successful commit. The Entity never dispatches. Full rules: 08.

## SERVICE LAYER MANDATE (ORCHESTRATOR, COMMAND/QUERY SEPARATION)
Services (Application layer) orchestrate Domain objects and Ports. No business logic. Split by intent. A single Service class MUST NOT mix command and query.

1. Command (write) Service: `<Module>CommandService` (or one final class per use case, e.g. `CreateActivityService`).
   - Flow: input DTO -> load Entity via WRITE port `<Module>RepositoryContract` (or create via named constructor) -> Entity behavior method -> persist via write port -> dispatch released events via EventDispatcher port -> return ResponseDTO.
   - Every write and every read-then-write MUST run in a transaction boundary (see MANDATORY TRANSACTION MANAGEMENT PROTOCOL). MUST use `TransactionManagerContract::run()` inside a private `executeInTransaction` helper.
   - MUST NOT return query-shaped data (lists, paginators, search results).
2. Query (read) Service: `<Module>QueryService` (or one final class per query, e.g. `ListActivitiesService`).
   - MUST NOT mutate state, record Domain Events, or call write methods.
   - Depends on the READ port `<Module>QueryContract` (`core/<Module>/Application/Contracts/`), NEVER on the write port. See CQRS PORT SPLIT. Uses read-optimized methods (filters, pagination, projections); need not hydrate Entities.
   - MUST return ResponseDTOs (or collections), never Entities or Eloquent models.
   - List/search input = `<Module>FilterDTO` built by the Request Mapper. Bounds (e.g. `per_page` min/max) are clamped there via a Domain `<Module>Rules` class, never hardcoded in Controllers/Services. The Query Service converts the DTO to a Domain `<Module>Filter` Value Object for the repository.
   - List/search reads return lightweight `<Module>ProjectionDTO` read models (`Domain/ReadModels/`), not Entities. Details: 05 (R12).

### INBOUND SERVICE CONTRACTS (MANDATORY)
Every module exposes both Services through inbound ports in `core/<Module>/Application/Contracts/`: `<Module>CommandServiceContract` (write surface) and `<Module>QueryServiceContract` (read surface).
- Controllers, Jobs, Console Commands, all delivery adapters constructor-inject the CONTRACT, NEVER the concrete Service class.
- The module binds both in its own `app/Providers/<Module>ServiceProvider.php` (P1).
- A module MAY add narrower query ports (e.g. `...QueryContract`, `...FormOptionsContract`) for a specific read concern. Additive, never a substitute for the two contracts.

### RESPONSE MAPPER SEPARATION
When a module has both sides, each MAY own its mapper and DTO (`<Module>ResponseMapper` for the write response, `<Module>ListResponseMapper` for list rows). Sharing is not required; a read mapper need not hydrate a full Entity.

## WRITE AND READ PORT PLACEMENT (CQRS PORT SPLIT)
Two persistence ports and two adapters per module. NEVER one shared repository.

| | Write | Read |
| --- | --- | --- |
| Port | `<Module>RepositoryContract` | `<Module>QueryContract` |
| Port location | `core/<Module>/Domain/Contracts/` | `core/<Module>/Application/Contracts/` |
| Adapter | `<Module>WriteRepository` | `<Module>ReadRepository` |
| Adapter extends | `BaseRepository` | nothing |
| Used by | Command Services | Query Services, presentation adapters |

- A WRITE port NEVER declares a pure read. The only read allowed on it is the row-locking `findByIdForUpdate()` taken immediately before the save. Test: is the read separable from the write it protects? If yes, it belongs on the read port, even if a Controller calls it. Presentation adapters inject the read port for reads and the write port for writes, never both for one concern.
- The read port lives in `Application/Contracts/` because its read models are Application types (ProjectionDTOs, detail DataObjects); Domain never references Application types. The write port stays in `Domain/Contracts/` because it exchanges pure Entities.
- `<Module>ReadRepository` extends NOTHING (no `BaseRepository`, no inherited write helpers; a read adapter that can `save()` is a boundary breach). It may use Eloquent directly (JOINs, projections) and returns Domain/read-model types only.
- A class NEVER implements both ports. A read method found on a write adapter moves to the read port/adapter.
- A narrow extra read surface (e.g. `<Module>FormOptionsContract`) gets its own small port and adapter, never a method on the write port.

## RESPONSE DTO MANDATE
Services return ResponseDTOs to every delivery mechanism (API, Web, Console). One Service output serves all.
- ResponseDTOs are `final readonly` classes (PHP 8.2+), plain types only (scalars, arrays, other DTOs, DateTimeImmutable), constructor property promotion.
- Services NEVER return Entities, Eloquent models, paginators, or framework Request/Response objects.
- Failures use the project's `Result` type wrapping the DTO (`Result::success($dto)` / `Result::failure($message)`).
- Formatting the ResponseDTO (JSON resource, Blade data, CLI output) is the Presentation adapter's job.
- Presentation adapters (Controllers, Console Commands, WordPress admin handlers) stay thin: parse input -> build input DTO -> call Service -> format ResponseDTO.

## MANDATORY TRANSACTION MANAGEMENT PROTOCOL
Any Service method that writes (create/update/delete) or does read-then-write MUST run in a transaction boundary using `TransactionManagerContract::run()`. Query Services NEVER open transactions.

1. Contract: `run(callable $callback): mixed` begins a transaction, runs the callback, commits on return, rolls back and rethrows on throw. Each environment implements it in its adapter (Laravel: closure DB transaction; WordPress: `$wpdb` or compensating-action manager). If the contract lacks `run()`, add it to the contract and every adapter in the same step.
2. Required pattern. Failure after a write is signalled by THROWING. The failure Result is built OUTSIDE `run()`. After-commit work happens after `run()` returns:

```php
$events = [];

try {
    $result = $this->transactionManager->run(function () use ($dto, &$events) {
        // 1. Lookup (findByIdForUpdate() for read-then-write)
        // 2. Domain logic (Entity behavior; rule violation throws a domain exception)
        // 3. Persistence through the repository
        return Result::success(data: $responseDTO, message: '...');
    });
} catch (SomeDomainException $exception) {
    // run() already rolled back
    return Result::failure($exception->getMessage(), 'DOMAIN_ERROR_KEY');
} catch (\Throwable $exception) {
    // log, remove a newly uploaded file, return a safe generic failure Result
}

// run() committed: dispatch $events, push jobs, delete replaced files
return $result;
```

3. Constraints:
   - NEVER use `DB::transaction()`, `DB::beginTransaction()`, or any framework transaction call in a Service.
   - NEVER call `beginTransaction()`/`commit()`/`rollback()` in a Service.
   - Closure scope: pass inputs with `use ($variable)`. Use `use (&$variable)` only for values needed after `run()` (e.g. `$events`, uploaded file path).
   - The closure returns the use-case `Result`; `run()` returns it.
   - NEVER swallow a Throwable inside the closure and return `Result::failure()` after a write happened: `run()` would COMMIT partial work. Throw; convert to a failure Result outside. `Result::failure()` inside the closure is allowed only for a guard before any write (not-found, pre-insert duplicate check).
   - After-commit work (dispatch events, push jobs, delete replaced files) runs after `run()` returns, never inside the closure.
4. Mandatory for: atomic multi-write use cases, race prevention (check-then-insert/update with `findByIdForUpdate()`), state transitions persisted immediately.
5. A write without a transaction wrapper is a critical architectural violation. Flag it as a bug in every review and self-check.
6. MANUAL FLOW (LEGACY, FORBIDDEN FOR NEW/REFACTORED CODE). A Command Service MUST NEVER use the port's `beginTransaction()` -> `commit()` -> `rollback()`. Existing legacy services using it stay valid ONLY while untouched. Any new or refactored Command Service MUST migrate to item 7.
7. DRY STANDARD (STRICT MANDATE). When a Command Service has write methods, do NOT duplicate try/catch and transaction calls per method. You MUST put `$this->transactionManager->run($operation)` and the shared catch blocks (TranslatorContract resolution, `LoggerInterface::error()`) in ONE private helper, e.g. `private function executeInTransaction(callable $operation, string $logMessage, string $fallbackMessageKey, string $fallbackErrorCode, array $context = []): Result`. Public use-case methods only orchestrate domain steps inside the closure. Pre-write guards (e.g. `$entity === null` after `findByIdForUpdate()`) may return `Result::failure(...)` from inside the closure; any failure after a write MUST throw.

## PORTS AND ADAPTERS MANDATE (FRAMEWORK INDEPENDENCE)
Everything Core needs from outside is an interface (Port) in Core, implemented by an Adapter outside Core. Create only ports the current task needs. Suffix is always `...Contract`, never `...Interface` or `I` prefix.
- Repository ports (`ActivityRepositoryContract`, `ActivityQueryContract`): this repo `WpActivityWriteRepository`, `WpActivityReadRepository` (`$wpdb`); Laravel build `EloquentActivityWriteRepository`, `EloquentActivityReadRepository`.
- `EventDispatcherContract`: Laravel dispatcher, WordPress `do_action`.
- `QueueManagerContract`: Laravel Queue, WordPress Action Scheduler / `wp_schedule_single_event`.
- `TransactionManagerContract`: Laravel DB transaction, `WpTransactionManager`, `CompensatingActionTransactionManager`.
- `TranslatorContract`: Laravel `trans()`; WordPress `WpTranslator` (key-based lang files, 02 section 9). Core gets user-facing text only through it.
- File storage, clock, logger, any other infrastructure capability: same Port/Adapter pattern.

Rules:
- Core NEVER uses facades, helpers, or globals (`Event::dispatch`, `dispatch()`, `Queue::push`, `now()`, `Storage::`, `do_action`, `wp_schedule_single_event`).
- Domain Events and Queue Jobs are plain PHP; never extend/implement framework classes.
- Framework wiring (container bindings, providers, WordPress bootstrap) lives outside Core.
- Adapters hold no business logic; they only translate between Core's contract and the framework.
- Core MUST NOT depend on a persistence shape. Infrastructure Mappers convert storage data <-> Entities.
- PORT METHOD NAMING: the name describes the DOMAIN OPERATION (`findById`), never the shape of a class implementing two ports. On a name collision, split the adapter into two classes; do not rename the method.
- NO VENDOR EXCEPTIONS IN CORE: an Application Service NEVER imports, catches, or type-hints a vendor/persistence exception (e.g. `Illuminate\Database\QueryException`). Infrastructure catches the duplicate-key error (SQLSTATE 23000 / unique violation) and rethrows `Core\Shared\Domain\Exceptions\DuplicateKeyException`. The Service catches that (or the module's own conflict exception) and returns a failure Result. No framework exception crosses the Core boundary in either direction.

## PROJECT CONVENTIONS (P-RULES)
Project-wide and as binding as the mandates above.

- P1 COMPOSITION ROOT PER MODULE: each module binds its own ports (`<Module>MapperContract`, `<Module>CommandServiceContract`, `<Module>QueryServiceContract`, `<Module>RepositoryContract`, any narrower query contract) in `app/Providers/<Module>ServiceProvider.php`, listed in the `providers` list of `config/app.php`. `AppServiceProvider` holds only Shared-kernel ports and framework-wide bindings, NEVER module bindings. Creating and registering the module provider is INHERENT WIRING for the step that creates the module. In this repo the composition root is the providers on `devlinkstudios/wp-service-container`; the Laravel build binds the same contracts in Laravel providers (`bootstrap/providers.php`).
- P2 MONEY IS A STRING: decimal money is `string`, never `float`, in DTOs, Entities, read models, ResponseDTOs. Format to the column scale once, in the Request Mapper.
- P3 DERIVED FIELDS ARE NOT CLIENT INPUT: a field derived from a state transition is not accepted from delivery input. The transition method decides it; delivery refuses a client value (e.g. Laravel `prohibited`).
- P4 UNCHANGED vs CLEAR: when an update must distinguish "leave unchanged" from "clear", use an explicit boolean flag (or three-state Value Object) per clearable field; `null` means "unchanged".
- P5 NATURAL KEYS FOR SEEDED ROWS: when a business rule maps to a seeded lookup row, match its stable natural key (e.g. name, case-insensitive), not its surrogate ID. Record the choice in `DECISIONS.md`.
- P6 MIXED-CURRENCY AGGREGATES: an aggregate summing a decimal column across mixed currencies MUST group by currency or state the single currency reported. A bare `SUM` under a hardcoded currency label is forbidden once more than one currency can exist.
- P7 CROSS-MODULE REPORTING PORTS: a module reporting on tables owned by other modules declares its own read ports and adapters inside itself. NEVER widen another module's port or import another module's repository.
- P14 CURRENT USER IDENTITY: the delivery adapter resolves the current user and passes it into the use case as a DTO field. Core NEVER reads session, auth, or request state.
- P15 LIFTING DELIVERY RULES INTO THE DOMAIN: a business rule existing only in delivery code (Blade/Controller) that no rule file or plan defines is lifted into the Entity UNCHANGED. Record its source plus a confirmation request in `DECISIONS.md`. Do not improve it in passing.
- P16 WIRE-CONTRACT PROPERTY NAMING IN RESPONSE DTOs: Domain/Application names are `camelCase` (01, NAMING PHILOSOPHY). A ResponseDTO property that IS the JSON key a client reads keeps the wire contract's casing (normally `snake_case`).
  - Test: does the property reach the client unconverted (e.g. `response()->json(['data' => $result->getData()])`)? Then its name is the contract; renaming it is a breaking API change.
  - One DTO, one casing. A new property on an existing DTO follows that DTO's casing. Never mix `camelCase` and `snake_case` in one constructor.
  - If delivery maps the DTO explicitly (Resource class, hand-built array), `camelCase` applies.
  - Request DTOs are unaffected. Never licenses an Entity, Value Object, or read model to break the naming philosophy.

## STRICT DOCBLOCK MANAGEMENT
- No informal narrative class descriptions ("This acts as a translation boundary...").
- A class implementing an interface MUST have `@implements <FQCN>`.
- Keep descriptions short, technical, 1-2 lines unless genuinely complex business logic needs more. No conversational text.
- Write like a human developer: plain everyday verbs ("Handles category creation", "Shows the edit form", "Saves the new category"). Avoid formal verbs ("Orchestrates", "Delegates use-case", "Translates").
- Keep structural tags (`@param`, `@return`, `@implements`).
- NO NOISE COMMENTS: never comment what self-documenting code already says. Inline comments only for a critical architectural decision or non-obvious business logic (the WHY).
- ZERO DEAD CODE: never leave commented-out code. Delete it.
- Example: `/** Handles category creation. @param CreateCategoryRequest $request @return Result */` (not "Orchestrates the category creation use-case.").

## PHP 8.X STANDARDS AND CONSTRUCTOR PROPERTY PROMOTION
When constructor property promotion is requested or required: NO property declarations above the constructor, NO assignments in the constructor body. Declare dependencies in the signature: `public function __construct(private readonly DependencyType $dependency) {}`.

## ERROR HANDLING AND DEFENSIVE PROGRAMMING
- Infrastructure catches framework-specific failures (e.g. `storeAs` returning false) and throws domain-agnostic or standard `RuntimeException`.
- Silent failures are forbidden.
- Uniqueness MUST be race-safe: database UNIQUE index, with the repository adapter mapping the duplicate-key error to the module's domain conflict exception. Check-then-insert alone is never enough.
- Business rule violations are raised from Domain (Entity/Value Object) as domain-specific exceptions or failures, NEVER framework exceptions.
- LOCALIZATION: no hardcoded user-facing text in Core; Core NEVER calls `__()`, `trans()`, or any translation helper. Text lives in lang files and reaches Core only via `TranslatorContract`. Error codes stay untranslated domain keys. Details: 02 (section 9), 05 (R12.6).

## INFRASTRUCTURE STRING ENCAPSULATION MANDATE
Services NEVER contain inline infrastructure strings (e.g. storage directory names). Define each as a private constant at the top of the Service (`private const THUMBNAIL_DIRECTORY = 'categories';`) and use `self::CONSTANT_NAME`.

## WORDPRESS-SPECIFIC CONVENTIONS
This file stays environment-neutral where it can. WordPress specifics (boot order, config/env, DI, Fluent Database repositories, security, routing, localization, OVERRIDE TABLE) live in 02. Load 02 when touching adapters, entity mappers, providers, routes, config or views. Rule files 03, 04, 05, 08 and 09 are WordPress editions; the OVERRIDE TABLE in 02 section 11 covers any Laravel wording that still appears.

## GLOBAL EXECUTION POLICY: INTERACTIVE ALERT
Trigger: every time you need user confirmation, permission, or input to proceed. Every HALT in Autonomous Execution Mode triggers it. Run BOTH commands (Windows), sound first, then toast:
1. `powershell.exe -Command "[System.Media.SystemSounds]::Asterisk.Play(); Start-Sleep -Milliseconds 300; [System.Media.SystemSounds]::Asterisk.Play()"`
2. `powershell.exe -Command "Add-Type -AssemblyName System.Windows.Forms; [System.Windows.Forms.MessageBox]::Show('Action required - please respond', 'Claude Code', [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Question)"`

## CODING STANDARDS
Early Return / Guard Clauses instead of complex ternaries for conditional logic and HTTP responses. Flat structure, no nested conditions.
```php
if ($result->isFailure()) {
    return back()->with('error', $message);
}
return redirect()->route('index');
```

## COMPLETION CHECKLIST (VERIFY BEFORE REPORTING DONE)
Audit the actual code against each applicable item. NEVER claim an item without confirming it in the code.
- The task's MODE was named before coding; no edit landed in `framework/` or `vendor/` outside a JOB F task.
- No `Illuminate\*`, WordPress function, `Framework\*`, `App\*`, or other framework import in Core Domain/Application (06 T8 grep returned zero matches).
- FROZEN folders are untouched by porting work (`tools/port-audit.sh` reports no difference).
- No `env()` outside `config/*.php`.
- Business rules and state transitions are in the Entity, not the Service.
- Service is Command OR Query, never both.
- Service returns a ResponseDTO (wrapped in `Result` where failure is possible), not an Entity or Eloquent model.
- Events and jobs go through Core ports and are plain PHP.
- Every new infrastructure capability has a Port in Core and an Adapter outside Core.
- Persistence split: write port (`Domain/Contracts/<Module>RepositoryContract` -> `<Module>WriteRepository extends BaseRepository`); read port (`Application/Contracts/<Module>QueryContract` -> `<Module>ReadRepository`, extends nothing). No pure read on the write port; no read adapter extends `BaseRepository`.
- Delivery adapters inject `<Module>CommandServiceContract` / `<Module>QueryServiceContract`, never the concrete Service.
- Each module binds its ports in its own `<Module>ServiceProvider`, listed in `config/app.php`.
- Every WordPress entry point checks nonce and capability; every database value is a bound parameter (02 section 7).
- No vendor exception imported, caught, or type-hinted in an Application Service; duplicate-key reaches Core only as a Shared Domain exception.
- Every Entity is `readonly class` (never `final`); nothing outside it writes its state.
- Every write MUST run in `TransactionManagerContract::run()` via the mandatory `executeInTransaction()` helper. Manual transactions are strictly forbidden.
- Before creating a file, its non-existence was verified (FILE EXISTENCE GUARD).

You are bound by these constraints for the entire project lifecycle.

---

## RULE FILE INDEX (`.claude/rules/`)
1. `01-universal-philosophy.txt` - ALWAYS relevant: Golden Rules, Hexagonal model, layer boundaries, SOLID/KISS, Definition of Done. Environment-neutral, shared by the Laravel project and this plugin.
2. `02-framework-adapter-wordpress.txt` - ALWAYS when touching `app/`, `config/`, `routes/`, `views/`, `database/`, providers, any `core/*/Infrastructure` adapter, the entry file, or porting. Boot order, config/env, DI, `$wpdb`, security, localization, OVERRIDE TABLE.
3. `03-module-scaffolding.txt` - ONLY when scaffolding a NEW module. WordPress edition.
4. `04-crud-pipeline.txt` - CRUD / use-case logic on an already-scaffolded module. WordPress edition.
5. `05-rich-domain-and-services.txt` - writing or changing an Entity, Value Object, Command/Query Service, ResponseDTO. Environment-neutral; adapter names and translation lines adapted.
6. `06-ports-and-portability.txt` - adding a port/adapter, touching Shared, PORT FREEZE, porting `core/` in EITHER direction, port audit.
7. `07-testing.txt` - ONLY when the developer explicitly asks for tests or re-enables testing. PAUSED: do not load otherwise.
8. `08-events-and-queues.txt` - Entity raises a Domain Event, Command Service dispatches events or pushes a job, or a listener/queue handler/adapter is written or reviewed. Environment-neutral.
9. `09-autonomous_execution.txt` - ALWAYS at the start of any step/module/plan execution. WordPress edition; a needed `framework/` change (H12) or FROZEN-folder edit (H13) is a hard stop.
10. `10-framework-package.txt` - ONLY in a JOB F task: building or changing `framework/` (the future Packagist package): boundaries, Laravel parity, public API, release readiness.
