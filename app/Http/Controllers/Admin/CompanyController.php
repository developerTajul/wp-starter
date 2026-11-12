<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use Core\Company\Application\Contracts\CompanyCommandServiceContract;
use Core\Company\Application\Contracts\CompanyMapperContract;
use Core\Company\Application\Contracts\CompanyQueryServiceContract;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;


final class CompanyController
{
    /** List screen slug: `admin.php?page=companies`. */
    public const SCREEN_SLUG = 'companies';

    /** Slug of the add-company screen under {@see self::SCREEN_SLUG}. */
    public const CREATE_SLUG = 'company-create';

    /** Slug of the edit-company screen under {@see self::SCREEN_SLUG}. */
    public const EDIT_SLUG = 'company-edit';

    /** Capability the screens and the form handlers require. */
    public const CAPABILITY = 'manage_options';

    /** `admin_post_{action}` name the add-company form posts to. */
    public const STORE_ACTION = 'company_store';

    /** `admin_post_{action}` name the edit-company form posts to. */
    public const UPDATE_ACTION = 'company_update';

    /** `admin_post_{action}` name the bulk-actions form posts to. */
    public const BULK_ACTION = 'company_bulk';

    /** `admin_post_{action}` name the list's row-action delete posts to. */
    public const DESTROY_ACTION = 'company_destroy';

    /** The bulk operation the form offers, as WordPress's own list tables send it. */
    private const BULK_DELETE = 'delete';

    /** The query key the list's sort column travels on. */
    private const ORDERBY_PARAM = 'orderby';

    /** The query key the list's sort direction travels on. */
    private const ORDER_PARAM = 'order';

    /** The query key the list's search term is written to. */
    private const SEARCH_PARAM = 's';

    /**
     * The query keys the search term is read from, in order.
     *
     * `s` is the convention; `search` is the legacy key this screen shipped
     * with, still honoured so an old address keeps filtering.
     *
     * @var list<string>
     */
    private const SEARCH_KEYS = [self::SEARCH_PARAM, 'search'];

    /** The column the list sorts by when the request names none. */
    private const DEFAULT_ORDERBY = 'created_at';

    /** The sort direction the list uses when the request names none. */
    private const DEFAULT_ORDER = 'desc';

    /** The parameter a row action names its record with. */
    private const ROW_ID_PARAM = 'id';

    public function __construct(
        private readonly CompanyCommandServiceContract $companyCommandService,
        private readonly CompanyQueryServiceContract $companyQueryService,
        private readonly CompanyMapperContract $mapper,
    ) {
    }

    /** Renders the companies list. */
    public function index(Request $request): ResponseInterface
    {
        $search = $this->searchTerm($request);

        $result = $this->companyQueryService->getPaginatedCompanies(
            $this->mapper->mapToFilterDTO($this->listQuery($search, $request))
        );

        $companies = [];
        $page = null;

        if ($result->isSuccess()) {
            $companies = $result->getData()->items();
            $page = $result->getData();
        }

        $totalCompanies = ($search === '' && $page !== null) ? $page->total() : $this->totalCompanies();

        return view('company.index', [
            'companies' => $companies,
            'page' => $page,
            'createUrl' => route('companies.create'),
            'search' => $search,
            'totalCompanies' => $totalCompanies,
            ...$this->listState($request, $search),
            ...$this->bulkWiring(),
            ...$this->rowActionWiring(),
        ])->withTitle('Companies');
    }

    /**
     * Reads the search term from `s`, then from the legacy `search` key.
     *
     * A non-string value (an array smuggled in under the key) is treated as no
     * search rather than cast.
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
     * Counts every company, ignoring the list's filters.
     *
     * Read through the same query service the list uses, with the search left
     * out. A failed read returns 0: the count is a label beside a link.
     */
    private function totalCompanies(): int
    {
        $result = $this->companyQueryService->getPaginatedCompanies(
            $this->mapper->mapToFilterDTO([
                'search' => null,
                // The smallest page the filter accepts. Only the count is used;
                // fetching a page of rows would read data this method discards.
                'per_page' => 1,
            ])
        );

        return $result->isSuccess() ? $result->getData()->total() : 0;
    }

    /**
     * Builds the query values the list read is built from.
     *
     * `per_page` comes from the request; the mapper validates it against the
     * domain's bounds and falls back to its default when the key is absent.
     *
     * @param string $search The term already resolved by {@see searchTerm()}.
     *
     * @return array<string, mixed>
     */
    private function listQuery(string $search, Request $request): array
    {
        return [
            'search' => $search === '' ? null : $search,
            'per_page' => $request->query('per_page'),
        ];
    }

    /**
     * Builds the list state the view's links preserve.
     *
     * `orderby` and `order` pass through as text: the read adapter holds the
     * whitelist and falls back to its default. `search` carries the resolved
     * term, so a link never re-emits the legacy key.
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
     * Builds the WordPress values the list's row actions need.
     *
     * `deleteFormIdFor` is a closure because each row gets a form of its own,
     * and the id has to name exactly one of them.
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

    /** Prefix of each row's own delete form id. */
    private const DELETE_FORM_ID = 'company-delete';

    /**
     * Builds the WordPress values the bulk-actions form needs to submit.
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
        return view('company.create', [
            'company' => null,
            ...$this->formWiring(self::STORE_ACTION, 'companies.index'),
        ])->withTitle('Add a company');
    }

    /** Handles the add-company submission. */
    public function store(StoreCompanyRequest $request): ResponseInterface
    {
        $companyCreateDTO = $this->mapper->mapToStoreDTO($request->validated());

        $result = $this->companyCommandService->createCompany($companyCreateDTO);

        if ($result->isFailure()) {
            return redirect()->route('companies.index')->with('error', $result->message());
        }

        return redirect()->route('companies.index')->with('notice', $result->message());
    }

    public function show(string $id): ResponseInterface
    {
        return view('company.show', ['company' => ['id' => $id]])->withTitle('Company');
    }

    /**
     * Renders the edit-company form for one record.
     *
     * A missing or unusable id redirects to the list rather than rendering an
     * empty form.
     */
    public function edit(Request $request): ResponseInterface
    {
        $id = $this->submittedId($request);

        if ($id === null) {
            return redirect()->route('companies.index')->with('error', 'No company was selected.');
        }

        $result = $this->companyQueryService->getCompanyForEdit($id);

        if ($result->isFailure()) {
            return redirect()->route('companies.index')->with('error', $result->message());
        }

        $company = $result->getData();

        return view('company.edit', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'website' => $company->website,
                'industry' => $company->industry,
                'phone' => $company->phone,
                'address' => $company->address,
            ],
            ...$this->formWiring(self::UPDATE_ACTION, 'companies.index'),
        ])->withTitle('Edit company');
    }

    /** Handles the edit-company submission. */
    public function update(UpdateCompanyRequest $request): ResponseInterface
    {
        $data = $request->validated();

        $result = $this->companyCommandService->updateCompany(
            $this->mapper->mapToUpdateDTO(
                companyId: (int) $data['id'],
                validatedData: $data,
            )
        );

        if ($result->isFailure()) {
            return redirect()->route('companies.index')->with('error', $result->message());
        }

        return redirect()->route('companies.index')->with('notice', $result->message());
    }

    /** Deletes one company, from the list's row action. */
    public function destroy(Request $request): ResponseInterface
    {
        $id = $this->submittedId($request);

        if ($id === null) {
            return redirect()->route('companies.index')->with('error', 'No company was selected.');
        }

        $result = $this->companyCommandService->deleteCompany($id);

        if ($result->isFailure()) {
            return redirect()->route('companies.index')->with('error', $result->message());
        }

        return redirect()->route('companies.index')->with('notice', $result->message());
    }

    /**
     * Reads the company id a row-action submission names, or null.
     *
     * Read through `input()` so the id is found from either a query parameter or
     * a form field.
     */
    private function submittedId(Request $request): ?int
    {
        return $this->toPositiveInt($request->input(self::ROW_ID_PARAM));
    }

    /**
     * Handles the list's bulk-actions form.
     *
     * An empty action (the dropdown left on "Bulk actions") is an ordinary
     * submission, not an error. Deletes run one id at a time through the
     * command service; the first failure stops the run.
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
            $result = $this->companyCommandService->deleteCompany($id);

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
     * Reads the company ids a bulk submission selected.
     *
     * Every shape is accepted (array, scalar, strings) and narrowed to positive
     * integers; anything else is dropped.
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
     * Casts a submitted value to a positive integer, or null.
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
     * Builds the WordPress values a company form needs to submit.
     *
     * The nonce is minted for `$action`, the same string the route's `nonce:`
     * middleware verifies.
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
