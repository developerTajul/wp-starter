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
    public const SCREEN_SLUG = 'companies';
    public const CREATE_SLUG = 'company-create';
    public const EDIT_SLUG = 'company-edit';
    public const CAPABILITY = 'manage_options';
    public const STORE_ACTION = 'company_store';
    public const UPDATE_ACTION = 'company_update';
    public const BULK_ACTION = 'company_bulk';
    public const DESTROY_ACTION = 'company_destroy';

    private const BULK_DELETE = 'delete';
    private const ROW_ID_PARAM = 'id';
    private const DELETE_FORM_ID = 'company-delete';

    public function __construct(
        private readonly CompanyCommandServiceContract $companyCommandService,
        private readonly CompanyQueryServiceContract $companyQueryService,
        private readonly CompanyMapperContract $mapper,
    ) {}

    /**
     * Renders the companies list.
     *
     * @param Request $request
     * @return ResponseInterface
     */
    public function index(Request $request): ResponseInterface
    {
        $result = $this->companyQueryService->getPaginatedCompanies(
            $this->mapper->mapToFilterDTO(
                ['per_page' => null, 'paged' => $this->currentPage($request)]
            )
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
     * The page the list is being read at, or 1 if the URL carries nothing.
     *
     * Read from `paged`, WordPress's own key for a list table's page - not from
     * `page`, which on a wp-admin screen names the menu slug the screen is
     * chosen by. The list's own pager links carry the same key, so the value
     * this reads is the one the view writes.
     *
     * @param Request $request
     * @return int
     */
    private function currentPage(Request $request): int
    {
        return $this->toPositiveInt($request->query('paged')) ?? 1;
    }

    /**
     * Builds row action wiring data.
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
     * Builds bulk action wiring data.
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

    /**
     * Shows the add company form.
     *
     * @return ResponseInterface
     */
    public function create(): ResponseInterface
    {
        return view('company.create', [
            'company' => null,
            ...$this->formWiring(self::STORE_ACTION, route('companies.create')),
        ])->withTitle('Add a company');
    }

    /**
     * Handles company creation.
     *
     * @param StoreCompanyRequest $request
     * @return ResponseInterface
     */
    public function store(StoreCompanyRequest $request): ResponseInterface
    {
        $StoreCompanyDTO = $this->mapper->mapToStoreDTO($request->validated());

        $result = $this->companyCommandService->createCompany($StoreCompanyDTO);

        if ($result->isFailure()) {
            return redirect()->route('companies.index')->with('error', $result->message());
        }

        return redirect()->route('companies.index')->with('notice', $result->message());
    }

    /**
     * Shows a single company.
     *
     * @param string $id
     * @return ResponseInterface
     */
    public function show(string $id): ResponseInterface
    {
        return view('company.show', ['company' => ['id' => $id]])->withTitle('Company');
    }

    /**
     * Shows the edit company form.
     *
     * @param Request $request
     * @return ResponseInterface
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

    /**
     * Handles company update.
     *
     * @param UpdateCompanyRequest $request
     * @return ResponseInterface
     */
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

    /**
     * Deletes a company.
     *
     * @param Request $request
     * @return ResponseInterface
     */
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
     * Reads the submitted company ID.
     *
     * @param Request $request
     * @return int|null
     */
    private function submittedId(Request $request): ?int
    {
        return $this->toPositiveInt($request->input(self::ROW_ID_PARAM));
    }

    /**
     * Handles bulk company deletion.
     *
     * @param Request $request
     * @return ResponseInterface
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
     * Reads submitted company IDs for bulk actions.
     *
     * @param Request $request
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

        return array_values(array_unique($ids));
    }

    /**
     * Casts a value to a positive integer.
     *
     * @param mixed $value
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
     * Builds form wiring data.
     *
     * @param string $action
     * @param string $redirectTo
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
     * Generates the edit screen URL for a record.
     *
     * @param int $companyId
     * @return string
     */
    private function editScreenUrl(int $companyId): string
    {
        $base = route('companies.edit');
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . http_build_query([self::ROW_ID_PARAM => $companyId]);
    }
}