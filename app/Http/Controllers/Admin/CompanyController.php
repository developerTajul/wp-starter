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


    /** The parameter a row action names its record with. */
    private const ROW_ID_PARAM = 'id';

    public function __construct(
        private readonly CompanyCommandServiceContract $companyCommandService,
        private readonly CompanyQueryServiceContract $companyQueryService,
        private readonly CompanyMapperContract $mapper,
    ) {
    }

    /** Renders the companies list. */
    public function index(): ResponseInterface
    {
        $result = $this->companyQueryService->getPaginatedCompanies(
            $this->mapper->mapToFilterDTO(['per_page' => null])
        );

        $companies = [];
        $page = null;

        if ($result->isSuccess()) {
            $companies = $result->getData()->items();
            $page = $result->getData();
        }

        return view('company.index', [
            'companies' => $companies,
            'page' => $page,
            'createUrl' => route('companies.create'),
            ...$this->bulkWiring(),
            ...$this->rowActionWiring(),
        ])->withTitle('Companies');
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
            ...$this->formWiring(self::STORE_ACTION, route('companies.create')),
        ])->withTitle('Add a company');
    }

    /** Handles the add-company submission. */
    public function store(StoreCompanyRequest $request): ResponseInterface
    {
        $result = $this->companyCommandService->createCompany(
            $this->mapper->mapToStoreDTO($request->validated())
        );

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
            ...$this->formWiring(self::UPDATE_ACTION, $this->editScreenUrl($company->id)),
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
     * `$redirectTo` is taken as an already-resolved URL rather than a route name,
     * because the edit form's return address names a record: a rejected update
     * has to come back to *that* company's form, and `route()` cannot build a URL
     * carrying a value the route's own path does not declare. The add form passes
     * `route('companies.create')` and the edit form passes
     * {@see self::editScreenUrl()}, so both spell the decision at the call site
     * that knows which screen the user was on.
     *
     * @param string $action     One of {@see self::STORE_ACTION} / {@see self::UPDATE_ACTION}.
     * @param string $redirectTo Resolved URL a rejected submission returns to.
     *
     * @return array<string, string>
     */
    private function formWiring(string $action, string $redirectTo): array
    {
        return [
            'formAction' => admin_url('admin-post.php'),
            'routeAction' => $action,
            'nonceField' => wp_nonce_field($action, '_wpnonce', true, false),
            'redirectTo' => $redirectTo,
        ];
    }

    /**
     * The edit screen's address for one record.
     *
     * `admin.php?page=company-edit&id=N`. The slug is the screen and the id is a
     * query parameter beside it, because WordPress picks an admin screen by
     * matching `?page=` against the slugs it was handed at `admin_menu`, and a
     * slug is one string with no segments.
     *
     * Built from the route's own URL rather than assembled by hand so the screen
     * slug has one source: appending a second `?` here would produce
     * `?page=company-edit?page=company-edit&id=7`, which PHP's parser reads as
     * `page=company-edit?page=company-edit` with no `id` at all, and the edit
     * screen would open the list instead of the record.
     *
     * @param int $companyId The record the address names.
     */
    private function editScreenUrl(int $companyId): string
    {
        $base = route('companies.edit');

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . http_build_query([self::ROW_ID_PARAM => $companyId]);
    }
}
