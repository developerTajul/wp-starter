<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\StoreLeadRequest;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Middleware\VerifyAdminNonce;
use Framework\Http\Responses\RedirectResponse;
use Framework\Support\ViewRenderer;

/**
 * The wp-admin surface for the Lead feature.
 *
 * ## Three actions, two WordPress mechanisms
 *
 * `index()` and `create()` are **screens**: WordPress calls them from
 * `admin_menu` and renders the admin chrome around whatever they return, so they
 * return rather than exit. `store()` is a **form handler**: it is reached through
 * `admin-post.php`, where the plugin owns the whole response, and it answers with
 * a redirect instead of a page.
 *
 * That split is why the three are in one class rather than two. They share the
 * slug constants below, and a reader looking for "what happens when a lead is
 * submitted" should not have to know that the word for it is `admin_post`.
 *
 * ## Why this does not extend Framework\Routing\Controller
 *
 * Same reason as {@see DashboardController}: that base class is REST-shaped, and
 * its response helpers build `WP_REST_Response` objects which emit nothing at all
 * through the web dispatch path.
 *
 * ## The slugs are constants because three places must agree
 *
 * A screen's slug appears in the route that registers it, in the `parent_slug` of
 * anything nested under it, and in every URL that points at it — a link on the
 * list screen, and the redirect a finished form issues. Three literals that must
 * agree is a typo waiting to become a 404, so they live here and everything else
 * reads them.
 *
 * ## The capability is a constant for the same reason
 *
 * The screen declares one in its menu options, and the form handler's route
 * enforces one through `'capability:' . self::CAPABILITY`. Two spellings of one
 * policy is how the REST side once ended up with two guards disagreeing about who
 * may read a lead — so the routes file reads this constant rather than restating
 * the string, and there is still exactly one place the answer lives. `list_users`
 * is the capability the REST endpoint already uses for "may work with other
 * people's contact details" — not a second, subtly different answer invented for
 * this screen.
 */
final class LeadController
{
    /**
     * The list screen's slug — `admin.php?page=leads`.
     */
    public const SCREEN_SLUG = 'leads';

    /**
     * The slug of the "Add a lead" screen nested under {@see self::SCREEN_SLUG}.
     */
    public const CREATE_SLUG = 'leads-create';

    /**
     * The capability both screens and the form handler require.
     */
    public const CAPABILITY = 'list_users';

    /**
     * The `admin_post_{action}` name the add-lead form is posted to.
     *
     * Declared here rather than left to the adapter's path-derived default, so
     * that the routes file, the nonce middleware and the form's hidden `action`
     * field all read one string. The adapter writes an action name back onto the
     * route after `flush()` for exactly this reason — see {@see \Framework\Routing\AdminPage::action()}
     * — but a caller that has not been through a flush yet (a unit test, a
     * controller rendering the form) has nothing to read back, so it is named
     * once here instead.
     */
    public const STORE_ACTION = 'leads_store';

    public function __construct(private readonly ViewRenderer $views)
    {
    }

    // ------------------------------------------------------------------ screens

    /**
     * The leads list — the "Leads" menu entry.
     *
     * Rendered through the layout, which owns the page heading and the
     * success/error notices. This action deliberately does not render the notice
     * itself: the flash bag is read once per renderer, and the layout is the one
     * place that decides how a notice looks.
     */
    public function index(): ResponseInterface
    {
        return $this->views->make('layout', [
            'title' => 'Leads',
            'content' => $this->views->render('leads.index', [
                'leads' => $this->leads(),
                'createUrl' => $this->screenUrl(self::CREATE_SLUG),
            ]),
        ]);
    }

    /**
     * The add-lead form.
     *
     * ## What the view is handed, and why none of it is built in the template
     *
     * Every value below is a WordPress fact — the handler's URL, the action name,
     * the nonce markup — and a view that called `admin_url()` or `wp_nonce_field()`
     * itself would be a template that cannot be rendered outside a wp-admin
     * request, which is what makes a view testable. So the controller assembles
     * them and the view only escapes them into attributes.
     *
     * ## Why `redirectTo` is this screen and not the list
     *
     * The form's own failure path comes back here. {@see \Framework\Http\Requests\FormRequest}
     * reads `redirect_to` when a submission is rejected, and it has to point at
     * the page holding the fields: landing on the list screen with a flashed
     * error map would show the summary notice and nowhere to attach the
     * per-field messages.
     */
    public function create(): ResponseInterface
    {
        return $this->views->make('layout', [
            'title' => 'Add a lead',
            'content' => $this->views->render('leads.create', [
                'formAction' => $this->adminUrl('admin-post.php'),
                'routeAction' => self::STORE_ACTION,
                'nonceField' => $this->nonceField(),
                'redirectTo' => $this->screenUrl(self::CREATE_SLUG),
            ]),
        ]);
    }

    // ------------------------------------------------------------ form handler

    /**
     * Accept a submitted lead.
     *
     * ## Validation is in the signature, not in the body
     *
     * The `StoreLeadRequest` parameter is the whole validation story: the
     * parameter binder builds it, validates it, and — when the rules fail —
     * returns the form request's own failure response *instead of* calling this
     * method. So a browser posting a bad form is redirected back to
     * {@see self::create()} with the errors and the old input flashed, and this
     * action is never entered. Nothing here can forget to validate.
     *
     * `validated()` returns only the keys the rules named, so a field the rules
     * did not mention cannot reach the body below — which is the difference
     * between reading `all()` and reading `validated()`.
     *
     * ## Nothing is persisted, and the notice says so
     *
     * There is no leads table yet — {@see self::leads()} explains where the rows
     * come from and where a repository belongs. The response is therefore honest
     * about having changed nothing rather than flashing a "saved" that would be
     * untrue. The rest of the loop is real: nonce check, capability check,
     * validation, redirect, flash.
     *
     * ## Why 303 and not 302
     *
     * This is the classic post/redirect/get shape, and 303 is the status that
     * says so: it tells the browser to follow the redirect with a GET, so a
     * refresh of the list screen re-reads it rather than resubmitting the form.
     * {@see RedirectResponse} accepts it, and `wp_safe_redirect()` carries it.
     */
    public function store(StoreLeadRequest $request): ResponseInterface
    {
        $data = $request->validated();

        return (new RedirectResponse($this->screenUrl(self::SCREEN_SLUG), 303, $request->request()))
            ->with('notice', sprintf(
                'Lead for %s was accepted, but nothing was stored: this starter has no leads table yet.',
                (string) ($data['email'] ?? 'nobody')
            ));
    }

    // ----------------------------------------------------------------- data

    /**
     * The rows this screen lists.
     *
     * ## Why this is a method and not a query
     *
     * The project has no leads table. Returning rows from a private method keeps
     * that honest — the screen, the route, the guard and the form loop are real,
     * the data is not — and swapping in a repository later changes this one
     * method rather than the shape of the class. {@see \App\Http\Controllers\Api\LeadController}
     * reads its rows the same way for the same reason.
     *
     * The duplication between the two controllers is deliberate and temporary:
     * these two demo lists are not a shared source, they are two copies of a
     * placeholder. The moment a leads table exists, both controllers should
     * consume one repository behind a contract — the pattern
     * {@see \Core\Note\Domain\NoteRepositoryContract} already establishes — and
     * this method should be deleted rather than made public.
     *
     * ## Why no `notes` column
     *
     * The REST endpoint gates a lead's internal notes behind a *separate*
     * capability (`read_lead_notes`), on the argument that an account trusted to
     * read contact details is not thereby trusted to read what the sales team
     * wrote about the customer. A screen visible to anyone holding `list_users`
     * that printed the notes column would quietly undo that distinction, so the
     * column is simply absent here — absent until granted, the same direction the
     * API fails in.
     *
     * @return list<array<string, mixed>>
     */
    private function leads(): array
    {
        return [
            [
                'id'           => 1,
                'name'         => 'Ada Lovelace',
                'email'        => 'ada@example.test',
                'phone'        => '555-0100',
                'submitted_at' => '2026-11-01T09:30:00Z',
            ],
            [
                'id'           => 2,
                'name'         => 'Grace Hopper',
                'email'        => 'grace@example.test',
                // No phone key at all, so the view's `??` fallback is exercised by
                // the demo data rather than only by a hypothetical row.
                'submitted_at' => '2026-11-02T14:05:00Z',
            ],
        ];
    }

    // -------------------------------------------------------------- WordPress

    /**
     * The URL of one of this feature's admin screens.
     */
    private function screenUrl(string $slug): string
    {
        return $this->adminUrl('admin.php?page=' . $slug);
    }

    /**
     * A wp-admin URL, or `''` outside WordPress.
     *
     * The guard is not defensiveness for its own sake: this controller is
     * exercised by unit tests that load no CMS, and rendering the form there must
     * not be a fatal. An empty action means the browser would post to the current
     * URL — a non-WordPress context, where there is no `admin-post.php` to post to
     * and therefore no submission to get wrong.
     */
    private function adminUrl(string $path): string
    {
        return function_exists('admin_url') ? (string) admin_url($path) : '';
    }

    /**
     * The nonce field the form must carry.
     *
     * Minted for {@see self::STORE_ACTION}, which is the action the route's
     * `'nonce:' . self::STORE_ACTION` parameter verifies against — both read the
     * same constant, so a form that renders is a form that passes. The field
     * *name* comes from {@see VerifyAdminNonce::FIELD} for the same reason: the
     * middleware reads `_wpnonce`, and a second spelling of it here would produce
     * a form that is rejected with "the security check is missing" and no clue
     * why.
     *
     * `$display = false` returns the markup instead of echoing it, because the
     * view decides where in the form it goes; echoing from here would print it
     * above the `<form>` element, outside the tag.
     */
    private function nonceField(): string
    {
        if (!function_exists('wp_nonce_field')) {
            return '';
        }

        return (string) wp_nonce_field(self::STORE_ACTION, VerifyAdminNonce::FIELD, true, false);
    }
}
