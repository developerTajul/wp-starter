<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use Core\Company\Application\Contracts\CompanyCommandServiceContract;
use Core\Company\Application\Contracts\CompanyMapperContract;
use Core\Company\Application\Contracts\CompanyQueryServiceContract;
use Core\Company\Application\DTOs\Requests\StoreCompanyDTO;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Support\ViewRenderer;

final class CompanyController
{
    /** List screen slug: `admin.php?page=companies`. */
    public const SCREEN_SLUG = 'companies';

    /** Slug of the add-company screen under {@see self::SCREEN_SLUG}. */
    public const CREATE_SLUG = 'companies-create';

    /**
     * Slug of the edit-company screen under {@see self::SCREEN_SLUG}.
     *
     * A slug of its own rather than a path under {@see self::SCREEN_SLUG}: the
     * slug is what WordPress matches `?page=` against, and it is one string with
     * no segments. The record being edited is named by an `id` query parameter
     * beside it.
     */
    public const EDIT_SLUG = 'companies-edit';

    /**
     * Capability the screens and the form handler require.
     *
     * `manage_options` rather than an invented name: an invented capability is
     * held by nobody until a project grants it, and `add_menu_page()` would
     * hide the screen from every user with no error.
     */
    public const CAPABILITY = 'manage_options';

    /**
     * `admin_post_{action}` name the add-company form posts to.
     *
     * Named here so the routes file, the nonce middleware and the form's
     * hidden `action` field all share one string. The adapter writes the
     * action name onto the route after `flush()`, but a caller that has not
     * been through a flush (a unit test, a controller rendering the form) has
     * nothing to read back.
     */
    public const STORE_ACTION = 'company_store';

    /**
     * `admin_post_{action}` name the edit-company form posts to.
     *
     * Distinct from {@see self::STORE_ACTION} because the two handlers take
     * different input; one action name would make them indistinguishable at
     * the `admin_post` hook.
     */
    public const UPDATE_ACTION = 'company_update';

    /**
     * `admin_post_{action}` name the bulk-actions form posts to.
     *
     * One action serves every bulk operation the list offers, because the list
     * has one form: WordPress addresses a handler by the form's `action` field,
     * and the operation the user chose arrives as a separate field the handler
     * switches on. A second action name per operation would mean a second form
     * and a second nonce for no gain.
     */
    public const BULK_ACTION = 'company_bulk';

    /**
     * `admin_post_{action}` name the list's row-action delete posts to.
     *
     * One id per submission, unlike {@see self::BULK_ACTION}, because the row
     * action belongs to one row. Kept as its own action rather than routed
     * through the bulk handler: a single delete and a bulk delete take different
     * input, and one action name would make them indistinguishable at the
     * `admin_post` hook.
     */
    public const DESTROY_ACTION = 'company_destroy';

    /**
     * Rows per list page.
     *
     * A constant rather than a config key: the page is a table in wp-admin, and
     * the number is a presentation choice this screen owns. It is passed to the
     * mapper, which is where every channel's bound is applied.
     */
    private const PER_PAGE = 20;

    /**
     * The bulk operation the form offers, as it arrives in the submission.
     *
     * The value is the one WordPress's own list tables send, so the markup and
     * the handler agree on a spelling a developer already knows.
     */
    private const BULK_DELETE = 'delete';

    /**
     * The `paged` key the list's page number is read from and written to.
     *
     * WordPress's own name for a list table's page. `page` cannot be used: on a
     * wp-admin screen it holds the menu slug, so a pager built on it would
     * navigate to a screen that does not exist.
     */
    private const PAGE_PARAM = 'paged';

    /**
     * The query keys the list's sort order travels on.
     *
     * The same names WordPress's own sortable list tables use, so the screen's
     * links and the read adapter's whitelist agree by convention rather than by
     * a second constant that could drift.
     */
    private const ORDERBY_PARAM = 'orderby';

    private const ORDER_PARAM = 'order';

    /**
     * The query key the list's search term is written to.
     *
     * `s`, WordPress's own name for a list table's search box. One spelling is
     * emitted even though two are accepted, so a link the screen generates never
     * perpetuates the legacy key.
     */
    private const SEARCH_PARAM = 's';

    /**
     * The query keys the search term is read from, in order.
     *
     * `s` is the convention; `search` is the key this screen shipped with before
     * it adopted one. Both are read so an address saved against the old spelling
     * keeps filtering - dropping it would show every row under a filled-in
     * search box, which reads as a broken filter rather than a renamed key.
     *
     * @var list<string>
     */
    private const SEARCH_KEYS = [self::SEARCH_PARAM, 'search'];

    /** The column the list sorts by when the request names none. */
    private const DEFAULT_ORDERBY = 'created_at';

    /** The sort direction the list uses when the request names none. */
    private const DEFAULT_ORDER = 'desc';

    /**
     * The parameter a row action names its record with.
     *
     * One name for both actions, because it is one concept: the row the user
     * acted on. The edit screen reads it from the query string, the delete
     * handler from the posted form, and {@see submittedId()} finds either.
     */
    private const ROW_ID_PARAM = 'id';

    public function __construct(
        private readonly ViewRenderer $views,
        private readonly CompanyCommandServiceContract $companies,
        private readonly CompanyQueryServiceContract $companyQueries,
        private readonly CompanyMapperContract $companyMapper,
    ) {
    }

    /**
     * Render the companies list.
     *
     * The read goes through the query service, never a repository: a controller
     * that reached for the read adapter would be reaching past the Application
     * layer it is meant to depend on.
     *
     * A failed read still renders the screen. This is an admin page, not an API
     * endpoint: an error page with no navigation leaves the user stuck, while a
     * list showing the error notice beside an empty table is recoverable. The
     * failure message is Core's own translated text, so it carries no SQL.
     *
     * The child view is rendered to a string and passed to `layout` as
     * `$content`.
     */
    public function index(Request $request): ResponseInterface
    {
        $search = $this->searchTerm($request);

        $result = $this->companyQueries->getPaginatedCompanies(
            $this->companyMapper->mapToFilterDTO($this->listQuery($search))
        );

        $companies = [];
        $page = null;

        if ($result->isSuccess()) {
            $companies = $result->getData()->items();
            $page = $result->getData();
        }

        return $this->views->make('layout', [
            'title' => 'Companies',
            'error' => $result->isFailure() ? $result->message() : null,
            'content' => $this->views->render('company.index', [
                'companies' => $companies,
                'page' => $page,
                'createUrl' => route('companies.create'),
                'search' => $search,
                'totalCompanies' => $this->totalCompanies(),
                ...$this->listState($request, $search),
                ...$this->bulkWiring(),
                ...$this->rowActionWiring(),
            ]),
        ]);
    }

    /**
     * The search term the list is filtered by.
     *
     * Read from `s` first, WordPress's own name for a list table's search box,
     * then from `search`, the key this screen used before it adopted the
     * convention. Both are honoured because a link or bookmark made against the
     * old spelling would otherwise stop filtering while still appearing to work:
     * the user would be shown the whole table under a populated search box.
     *
     * A non-string (an array smuggled in under the key) is treated as no search
     * at all rather than cast, which would produce the string "Array" and filter
     * on it.
     */
    private function searchTerm(Request $request): string
    {
        foreach (self::SEARCH_KEYS as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * How many companies the site holds in total, ignoring the list's filters.
     *
     * This is the number the status filter view shows next to `All`, so it must
     * not move when the user types a search - WordPress's own `All` count is the
     * table's size, not the result set's.
     *
     * Read through the same query service and filter DTO the list uses, with the
     * search left out. That reuses the count the pagination path already had to
     * make, rather than adding a method to the read port, which is FROZEN.
     *
     * A failed read returns 0. The count is a label beside a link, and a screen
     * that cannot count its rows can still list them.
     */
    private function totalCompanies(): int
    {
        $result = $this->companyQueries->getPaginatedCompanies(
            $this->companyMapper->mapToFilterDTO([
                'search' => null,
                // The smallest page the filter accepts. Only the count is used;
                // fetching a page of rows would read data this method discards.
                'per_page' => 1,
            ])
        );

        return $result->isSuccess() ? $result->getData()->total() : 0;
    }

    /**
     * The query values the list read is built from.
     *
     * `paged` is read through the request rather than by the repository, which
     * reads `$_GET` itself; both see the same WordPress request, and keeping the
     * page here would have meant a second source of truth for what `paged=2`
     * means. `per_page` is this screen's own constant, so a URL cannot ask for
     * the whole table.
     *
     * @param string $search The term already resolved by {@see searchTerm()}.
     *
     * @return array<string, mixed>
     */
    private function listQuery(string $search): array
    {
        return [
            'search' => $search === '' ? null : $search,
            'per_page' => self::PER_PAGE,
        ];
    }

    /**
     * Where the list currently is, for the view's links to preserve.
     *
     * The view builds its sort links and its pager from these, so following
     * either keeps the search term, the sort column and the direction the user
     * is already looking at. Without them a sort click would silently drop the
     * search, and a pager click would silently reset the sort.
     *
     * `orderby` and `order` are passed through as text rather than validated
     * here: the read adapter holds the whitelist, and a value it does not
     * recognise falls back to the default there. Validating in both places would
     * be two lists to keep in step.
     *
     * `search` carries the ALREADY RESOLVED term, not the raw query value: the
     * screen accepts two spellings and the view must link with one, or a link
     * made under the legacy key would keep re-emitting the legacy key forever.
     *
     * @param string $search The term already resolved by {@see searchTerm()}.
     *
     * @return array<string, mixed>
     */
    private function listState(Request $request, string $search): array
    {
        $orderby = $request->query(self::ORDERBY_PARAM);
        $order = $request->query(self::ORDER_PARAM);

        return [
            'searchUrl' => route('companies.index'),
            'searchKey' => self::SEARCH_PARAM,
            'search' => $search,
            'orderby' => is_string($orderby) && $orderby !== '' ? $orderby : self::DEFAULT_ORDERBY,
            'order' => strtolower(is_string($order) && $order === 'asc' ? 'asc' : self::DEFAULT_ORDER),
        ];
    }

    /**
     * WordPress values the list's row actions need.
     *
     * The edit screen is reached by URL, so its address is built here where the
     * route name is known. That address already names the screen: the route's
     * path is the slug, and the URL generator emits `admin.php?page={slug}` from
     * it. The view therefore adds only the record id - passing the slug again
     * would emit `page` twice.
     *
     * The delete is a write and is posted, so it needs the same three pieces
     * every form on this screen needs: where to post, which action the route
     * declares, and a nonce minted for that exact string.
     *
     * `deleteFormIdFor` is a closure because each row gets a form of its own -
     * see the list view - and the id has to name exactly one of them. It is
     * built from the constant rather than the record's id alone, so the prefix
     * has one home.
     *
     * @return array<string, mixed>
     */
    private function rowActionWiring(): array
    {
        return [
            'editUrl' => route('companies.edit'),
            'editPageParam' => 'page',
            'editPageValue' => self::EDIT_SLUG,
            'editIdParam' => self::ROW_ID_PARAM,
            'deleteFormAction' => admin_url('admin-post.php'),
            'deleteRouteAction' => self::DESTROY_ACTION,
            'deleteNonceField' => wp_nonce_field(self::DESTROY_ACTION, '_wpnonce', true, false),
            'deleteIdParam' => self::ROW_ID_PARAM,
            'deleteFormIdFor' => static fn (int $companyId): string => sprintf(
                '%s-%d',
                self::DELETE_FORM_ID,
                $companyId,
            ),
        ];
    }

    /**
     * Prefix of each row's own delete form id.
     *
     * The list view renders one small form per row and pairs it with the row's
     * Delete button through the `form` attribute. Named here so the view and the
     * controller cannot drift apart on it.
     */
    private const DELETE_FORM_ID = 'company-delete';

    /**
     * WordPress values the bulk-actions form needs to submit.
     *
     * The nonce is minted for {@see self::BULK_ACTION}, the same string the
     * route's `nonce:` middleware verifies. The action field is the route's own
     * action name, which is what makes WordPress dispatch the submission to
     * {@see bulkDestroy()}.
     *
     * @return array<string, string>
     */
    private function bulkWiring(): array
    {
        return [
            'formAction' => admin_url('admin-post.php'),
            'routeAction' => self::BULK_ACTION,
            'nonceField' => wp_nonce_field(self::BULK_ACTION, '_wpnonce', true, false),
            'redirectTo' => route('companies.index'),
        ];
    }

    public function create(): ResponseInterface
    {
        return $this->views->make('layout', [
            'title' => 'Add a company',
            'content' => $this->views->render('company.create', [
                'company' => null,
                ...$this->formWiring(self::STORE_ACTION, 'companies.index'),
            ]),
        ]);
    }

    /**
     * Handle the add-company submission.
     *
     * A type-hinted {@see StoreCompanyRequest} is built and validated before
     * this method runs, so there is no `validate()` call and no branch for a
     * rejected submission.
     *
     * `validated()` returns only the keys the rules named, so form plumbing
     * such as `action`, `_wpnonce` and `redirect_to` cannot reach `$data`.
     *
     * The Service decides everything else: it builds the entity, persists it and
     * returns a Result whose message is already translated. This method only
     * moves input in and turns the outcome into a redirect.
     *
     * @return ResponseInterface The list screen, with a flash message.
     */
    public function store(StoreCompanyRequest $request): ResponseInterface
    {
        $data = $request->validated();

        $result = $this->companies->createCompany(new StoreCompanyDTO(
            name: (string) $data['name'],
            website: $this->optionalString($data['website'] ?? null),
            industry: $this->optionalString($data['industry'] ?? null),
            phone: $this->optionalString($data['phone'] ?? null),
            address: $this->optionalString($data['address'] ?? null),
        ));

        // Both a created company and a refused one return to the list; the flash
        // key is what tells them apart, and the create screen is reached again
        // from the list rather than by bouncing back to the form.
        if ($result->isFailure()) {
            return redirect()->route('companies.index')->with('error', $result->message());
        }

        return redirect()->route('companies.index')->with('notice', $result->message());
    }

    public function show(string $id): ResponseInterface
    {
        return $this->views->make('layout', [
            'title' => 'Company',
            'content' => $this->views->render('company.show', [
                'company' => ['id' => $id],
            ]),
        ]);
    }

    /**
     * Render the edit-company form for one record.
     *
     * The record is loaded through the query service, which is where a read
     * belongs: a controller that reached for the read adapter would be reaching
     * past the Application layer it depends on.
     *
     * A missing or unusable id redirects to the list with an error notice rather
     * than rendering an empty form. An edit screen with blank fields and an
     * Update button is an invitation to overwrite a record with nothing, and the
     * id it was asked for is the only thing wrong.
     */
    public function edit(Request $request): ResponseInterface
    {
        $id = $this->submittedId($request);

        if ($id === null) {
            return redirect()->route('companies.index')->with('error', 'No company was selected.');
        }

        $result = $this->companyQueries->getCompanyForEdit($id);

        if ($result->isFailure()) {
            return redirect()->route('companies.index')->with('error', $result->message());
        }

        $company = $result->getData();

        return $this->views->make('layout', [
            'title' => 'Edit company',
            'content' => $this->views->render('company.edit', [
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'website' => $company->website,
                    'industry' => $company->industry,
                    'phone' => $company->phone,
                    'address' => $company->address,
                ],
                ...$this->formWiring(self::UPDATE_ACTION, 'companies.index'),
            ]),
        ]);
    }

    /**
     * Handle the edit-company submission.
     *
     * A type-hinted {@see UpdateCompanyRequest} is built and validated before
     * this method runs, so there is no `validate()` call and no branch for a
     * rejected submission - {@see FormRequest} already redirected back to the
     * form with the errors and the old input.
     *
     * The record is named by the validated `id`, which arrives as a form field:
     * the route is an `admin_post_{action}` handler and an action name cannot
     * carry a placeholder. `validated()` returns the raw input under an
     * `integer` rule, so the cast is what makes the type match the mapper.
     *
     * `validated()` returns only the keys the rules named, so form plumbing such
     * as `action`, `_wpnonce` and `redirect_to` cannot reach the mapper - and
     * the mapper reads a missing key as "leave this field unchanged", so that
     * separation is what stops plumbing from being written into the record.
     *
     * The Service decides everything else: it loads the record, lets the entity
     * apply the change, persists it and returns a Result whose message is
     * already translated. This method only moves input in and turns the outcome
     * into a redirect.
     */
    public function update(UpdateCompanyRequest $request): ResponseInterface
    {
        $data = $request->validated();

        $result = $this->companies->updateCompany(
            $this->companyMapper->mapToUpdateDTO(
                companyId: (int) $data['id'],
                validatedData: $data,
            )
        );

        // Both an updated company and a refused one return to the list; the
        // flash key is what tells them apart, and the list is where the change
        // is visible. A rejected submission never reaches here - the request
        // sent the user back to the form itself.
        if ($result->isFailure()) {
            return redirect()->route('companies.index')->with('error', $result->message());
        }

        return redirect()->route('companies.index')->with('notice', $result->message());
    }

    /**
     * Delete one company, from the list's row action.
     *
     * The route's `nonce:` and `capability:` middleware have already run, so a
     * submission that reaches here is authenticated and addressed to this
     * action. What remains is the id, which is user input and is narrowed before
     * it is used.
     *
     * The delete runs through the command service rather than a repository, so
     * the entity's own rules and the repository's failure translation stay on
     * the one path every other delete uses.
     */
    public function destroy(Request $request): ResponseInterface
    {
        $id = $this->submittedId($request);

        if ($id === null) {
            return redirect()->route('companies.index')->with('error', 'No company was selected.');
        }

        $result = $this->companies->deleteCompany($id);

        if ($result->isFailure()) {
            return redirect()->route('companies.index')->with('error', $result->message());
        }

        return redirect()->route('companies.index')->with('notice', $result->message());
    }

    /**
     * The company id a row-action submission names, or null.
     *
     * Read through `input()` so the id is found whichever source carries it: a
     * query parameter on the edit screen's URL, or a form field on the delete
     * handler's POST. Anything that is not a positive integer is refused rather
     * than cast, because `(int) 'abc'` is `0` and would aim the action at no row.
     */
    private function submittedId(Request $request): ?int
    {
        return $this->toPositiveInt($request->input(self::ROW_ID_PARAM));
    }

    /**
     * Handle the list's bulk-actions form.
     *
     * The route's `nonce:` and `capability:` middleware have already run, so a
     * submission that reaches here is authenticated and addressed to this
     * action. What is checked here is the operation the user chose: the form
     * posts an empty action when the dropdown is left on "Bulk actions", which
     * is an ordinary submission and not an error.
     *
     * The delete runs one id at a time through the command service, each id in
     * its own transaction. A bulk-capable port would be a second way to delete
     * a company, and the module already has one; composing the existing use
     * case keeps the entity's own rules and the repository's own failure
     * translation on the one path everything else uses.
     *
     * Ids are filtered to positive integers before anything is deleted. The
     * form's field is user input, and `$_POST['company_ids']` may arrive as a
     * single scalar, as strings, or as an array holding either - each is
     * normalised rather than trusted.
     *
     * @return ResponseInterface The list screen, with a flash message.
     */
    public function bulkDestroy(Request $request): ResponseInterface
    {
        $operation = $request->input('bulk_action');

        if (! is_string($operation) || $operation !== self::BULK_DELETE) {
            return redirect()->route('companies.index');
        }

        $ids = $this->submittedIds($request);

        if ($ids === []) {
            return redirect()->route('companies.index')->with('error', 'No companies were selected.');
        }

        $deleted = 0;
        $failure = null;

        foreach ($ids as $id) {
            $result = $this->companies->deleteCompany($id);

            if ($result->isFailure()) {
                // The first failure stops the run. Later ids are left alone
                // rather than attempted, so the notice names a count that
                // matches what the user will find.
                $failure = $result->message();

                break;
            }

            $deleted++;
        }

        if ($failure !== null) {
            return redirect()->route('companies.index')->with('error', $failure);
        }

        return redirect()->route('companies.index')->with(
            'notice',
            sprintf('%d compan%s deleted.', $deleted, $deleted === 1 ? 'y' : 'ies'),
        );
    }

    /**
     * The company ids a bulk submission selected.
     *
     * A checkbox group posts `company_ids[]`, which arrives as an array of
     * strings. A single selection may arrive as a bare scalar instead, and a
     * crafted request may put anything at all under the key, so every shape is
     * accepted and then narrowed to positive integers. Anything else is dropped
     * rather than cast: `(int) 'abc'` is `0`, which would be a delete aimed at
     * no row.
     *
     * @return list<int>
     */
    private function submittedIds(Request $request): array
    {
        $raw = $request->input('company_ids');

        if ($raw === null || $raw === '') {
            return [];
        }

        $candidates = is_array($raw) ? $raw : [$raw];
        $ids = [];

        foreach ($candidates as $candidate) {
            $id = $this->toPositiveInt($candidate);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        // Duplicates would delete the same row twice, and the second attempt
        // would report a not-found failure for a row the first attempt removed.
        return array_values(array_unique($ids));
    }

    /**
     * A submitted value as a positive integer, or null.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private function toPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * A submitted optional field as a nullable string.
     *
     * An empty form field arrives as `''`, which is not the same as "no value":
     * passing it on would store an empty string where the entity means null, and
     * the edit form would then render a value the user never typed.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * WordPress values a company form needs to submit.
     *
     * Kept here because the controller knows which action the route
     * registered and which capability guards it.
     *
     * The nonce is minted for `$action`, the same string the route's `nonce:`
     * middleware verifies. A nonce minted for one action and verified against
     * another fails every submission.
     *
     * @param string $action     One of {@see self::STORE_ACTION} / {@see self::UPDATE_ACTION}.
     * @param string $redirectTo Route name a rejected submission returns to.
     *
     * @return array<string, string>
     */
    private function formWiring(string $action, string $redirectTo): array
    {
        return [
            'formAction' => admin_url('admin-post.php'),
            'routeAction' => $action,
            'nonceField' => wp_nonce_field($action, '_wpnonce', true, false),
            'redirectTo' => route($redirectTo),
        ];
    }
}
