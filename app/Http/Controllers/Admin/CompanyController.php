<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\StoreCompanyRequest;
use Core\Company\Application\Contracts\CompanyCommandServiceContract;
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

    public function __construct(
        private readonly ViewRenderer $views,
        private readonly CompanyCommandServiceContract $companies,
    ) {
    }

    /**
     * Render the companies list.
     *
     * The child view is rendered to a string and passed to `layout` as
     * `$content`.
     */
    public function index(): ResponseInterface
    {
        return $this->views->make('layout', [
            'title' => 'Companies',
            'content' => $this->views->render('company.index', [
                'companies' => [],
            ]),
        ]);
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

        return redirect()->route('companies.index')->with('success', $result->message());
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

    public function edit(string $id): ResponseInterface
    {
        return $this->views->make('layout', [
            'title' => 'Edit company',
            'content' => $this->views->render('company.edit', [
                'company' => ['id' => $id],
                ...$this->formWiring(self::UPDATE_ACTION, 'companies.index'),
            ]),
        ]);
    }

    public function update(Request $request, string $id): ResponseInterface
    {
        return redirect()->route('companies.index')->with('success', 'Updated.');
    }

    public function destroy(string $id): ResponseInterface
    {
        return redirect()->route('companies.index')->with('success', 'Deleted.');
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
