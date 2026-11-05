<?php
declare(strict_types=1);

namespace App\Providers;

use App\Database\WpTransactionManager;
use App\Repositories\WpNoteAuditRepository;
use App\Repositories\WpNoteRepository;
use Core\Note\Domain\NoteAuditContract;
use Core\Note\Domain\NoteRepositoryContract;
use Core\Shared\Contracts\TransactionManagerContract;
use DevLinkStudios\WPServiceContainer\ServiceProvider;

/**
 * Binds the note module's adapters to its ports.
 *
 * ## Why this is a provider of its own
 *
 * The bindings are three lines, and they still belong here rather than in
 * {@see AppServiceProvider}. The container resolves `NoteService` by
 * type-hinting {@see NoteRepositoryContract}, {@see NoteAuditContract} and
 * {@see TransactionManagerContract}, so *something* has to answer all three
 * names before the first use case runs — and putting them in a provider named
 * for the module they serve means a project can drop the notes module by
 * removing one entry from `config('app.providers')`, rather than by editing a
 * general-purpose file that also wires unrelated things.
 *
 * `AppServiceProvider` is deliberately left alone. It is the file a project
 * edits on day one, and bindings that arrive with the framework — and are
 * replaced when a project swaps a driver — do not belong in it.
 *
 * ## Why two are `bind()` and one is `singleton()`
 *
 * The two repositories hold no state: a `$wpdb` handle and nothing else. Binding
 * them non-shared keeps a per-request `$wpdb` from being captured in a long-lived
 * object, which is the failure mode that appears only under a persistent process
 * — the same reasoning as {@see \Framework\Http\HttpServiceProvider}'s non-shared
 * `Pipeline`. The cost is that `NoteService` builds them per resolve, and a
 * `$wpdb` wrapper is cheap enough for that to be the right trade.
 *
 * The transaction manager is the exception, and it must be: it holds the nesting
 * depth, and a fresh instance per resolve would count every frame from zero. An
 * `NoteService` handed its own manager would see `recordAudit()`'s nested call as
 * outermost and issue a real `START TRANSACTION` inside the service's — the exact
 * bug the depth rule exists to prevent. Sharing it is what makes the join work,
 * so the two are not inconsistent; they are chosen for opposite reasons.
 *
 * The `wpdb` handle is *not* injected by the container. WordPress owns that
 * object, and a type-hint on `wpdb` would resolve through the container's
 * auto-wiring to a freshly constructed bare instance rather than the global one —
 * a second handle that is not connected to the site's database. Each adapter
 * therefore resolves the global lazily, in its own constructor default, which is
 * the same seam {@see \Framework\Database\Migrator} uses.
 *
 * ## Configure it
 *
 * Add this class to the `providers` array in `config/app.php`:
 *
 *     \App\Providers\RepositoryServiceProvider::class,
 *
 * It must be listed **before** anything that resolves `NoteService` during
 * `boot()`. Registration order is provider-list order, and a `boot()` that
 * resolves the service before this has registered would fail to build it.
 */
final class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The port => adapter bindings the container answers `NoteService`'s
        // constructor type-hints from. All three are needed: the service declares
        // three ports, and a container that can build two of them still fails on
        // the third. Without these, resolving NoteService fails with "cannot
        // instantiate interface", which is the intended failure — an unbound port
        // should be loud rather than silently defaulted.
        $this->container->bind(
            NoteRepositoryContract::class,
            static fn (): WpNoteRepository => new WpNoteRepository()
        );

        $this->container->bind(
            NoteAuditContract::class,
            static fn (): WpNoteAuditRepository => new WpNoteAuditRepository()
        );

        // A *singleton*, unlike the two repositories. The depth counter is what
        // makes a nested `transaction()` join rather than open a second, and a
        // fresh manager per resolve would mean every injection point counted from
        // zero — so the audit write inside `NoteService::create()` would look
        // outermost to its own manager and issue a real `START TRANSACTION`
        // inside the service's. That is the exact bug the depth rule exists to
        // prevent, and it would be invisible except as a note that commits when
        // its transaction rolled back.
        $this->container->singleton(
            TransactionManagerContract::class,
            static fn (): WpTransactionManager => new WpTransactionManager()
        );
    }
}
