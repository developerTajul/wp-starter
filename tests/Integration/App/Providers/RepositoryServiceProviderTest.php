<?php
declare(strict_types=1);

namespace Tests\Integration\App\Providers;

use App\Database\WpTransactionManager;
use App\Providers\RepositoryServiceProvider;
use App\Repositories\WpNoteAuditRepository;
use App\Repositories\WpNoteRepository;
use Core\Note\Application\NoteService;
use Core\Note\Domain\NoteAuditContract;
use Core\Note\Domain\NoteAuditEntry;
use Core\Note\Domain\NoteEntity;
use Core\Note\Domain\NoteRepositoryContract;
use Core\Shared\Contracts\TransactionManagerContract;
use Core\Shared\PaginatedResult;
use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use DevLinkStudios\WPServiceContainer\Exceptions\BindingResolutionException;
use PHPUnit\Framework\TestCase;

/**
 * The port bindings, as the container actually answers them.
 *
 * ## What this test is for
 *
 * `NoteService` type-hints three *interfaces*, which the container cannot build
 * on its own. This provider is the only thing that answers all three, and
 * without it the first use case that asks for the service fails at request time
 * rather than at boot. So this asserts the links: each port is bound, and the
 * service built from those bindings is the configured adapter rather than
 * something else.
 *
 * ## Why every port is bound by the provider now
 *
 * It used to leave the transaction port to the caller — the reasoning being that
 * a project wires its own transaction manager. That was true while `NoteService`
 * had two ports and no application binding answered the second; the effect was
 * that `resolve(NoteService::class)` *failed* in a real app, and this test passed
 * only because it placed a stub first.
 *
 * An end-to-end-resolvable service is the property worth having, and it is not
 * achievable by a provider that binds two thirds of what the service declares.
 * So all three are bound, and the "without the binding" controls below take each
 * one away in turn to show which port is load-bearing — a single control on one
 * port would let the other two regress unnoticed.
 */
final class RepositoryServiceProviderTest extends TestCase
{
    private function container(): Container
    {
        $container = new Container();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Container::class, $container);

        return $container;
    }

    /**
     * The configured app: the provider, and nothing placed by hand.
     *
     * Deliberately no stubs. A test that pre-binds a port cannot tell whether the
     * provider would have — which is exactly how the transaction port went
     * unnoticed as unbound.
     */
    private function app(): Container
    {
        $container = $this->container();

        // register() only declares bindings, so it needs no boot phase — the
        // two-phase contract the base provider documents.
        $container->register(new RepositoryServiceProvider($container));

        return $container;
    }

    public function test_the_note_port_is_bound(): void
    {
        $this->assertTrue(
            $this->app()->bound(NoteRepositoryContract::class),
            'The provider must bind the port the service depends on.'
        );
    }

    public function test_the_audit_port_is_bound(): void
    {
        $this->assertTrue(
            $this->app()->bound(NoteAuditContract::class),
            'The service declares an audit port; leaving it unbound makes the service unresolvable.'
        );
    }

    public function test_the_transaction_port_is_bound(): void
    {
        $this->assertTrue(
            $this->app()->bound(TransactionManagerContract::class),
            'The service declares a transaction port; it must not be left to the caller.'
        );
    }

    public function test_resolving_the_note_port_yields_the_wpdb_adapter(): void
    {
        $this->assertInstanceOf(
            WpNoteRepository::class,
            $this->app()->resolve(NoteRepositoryContract::class)
        );
    }

    public function test_resolving_the_audit_port_yields_the_wpdb_adapter(): void
    {
        $this->assertInstanceOf(
            WpNoteAuditRepository::class,
            $this->app()->resolve(NoteAuditContract::class)
        );
    }

    public function test_resolving_the_transaction_port_yields_the_wpdb_manager(): void
    {
        $this->assertInstanceOf(
            WpTransactionManager::class,
            $this->app()->resolve(TransactionManagerContract::class)
        );
    }

    /**
     * The end the bindings exist for: the service, which declares three
     * interfaces, is buildable — with nothing placed by hand.
     */
    public function test_the_note_service_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(
            NoteService::class,
            $this->app()->resolve(NoteService::class)
        );
    }

    /**
     * `NoteService` and the audit path must share *one* transaction manager.
     *
     * This is the assertion behind the provider's `singleton()` choice, and it is
     * the difference between a nested `transaction()` joining and a second one
     * opening. Two managers would each count from zero, so the audit write inside
     * `NoteService::create()` would look outermost to its own manager and issue a
     * real `START TRANSACTION` inside the service's — committing the note before
     * the outer frame decided whether to.
     *
     * A non-shared binding is the obvious-looking choice and passes every other
     * test in this file, which is why this one exists.
     */
    public function test_the_transaction_manager_is_shared(): void
    {
        $container = $this->app();

        $this->assertSame(
            $container->resolve(TransactionManagerContract::class),
            $container->resolve(TransactionManagerContract::class),
            'A fresh manager per resolve would count every nesting frame from zero.'
        );
    }

    /**
     * Each port's absence is what makes the service unresolvable — so the
     * bindings above are load-bearing rather than incidental.
     *
     * Asserted per port rather than once: a single control on one port would let
     * the other two regress while this file stayed green.
     */
    public function test_without_the_note_binding_the_service_cannot_be_resolved(): void
    {
        $this->assertServiceFailsWithout(NoteRepositoryContract::class);
    }

    public function test_without_the_audit_binding_the_service_cannot_be_resolved(): void
    {
        $this->assertServiceFailsWithout(NoteAuditContract::class);
    }

    public function test_without_the_transaction_binding_the_service_cannot_be_resolved(): void
    {
        $this->assertServiceFailsWithout(TransactionManagerContract::class);
    }

    /**
     * A container with the provider registered but one port unbound: the service
     * fails, and the failure names the missing port.
     *
     * @param class-string $missing
     */
    private function assertServiceFailsWithout(string $missing): void
    {
        $container = $this->container();

        // Every port *except* the one under test, bound directly. The provider is
        // deliberately not registered, so this isolates the port rather than the
        // provider — the tests above already show the provider binds them all.
        foreach ([
            NoteRepositoryContract::class,
            NoteAuditContract::class,
            TransactionManagerContract::class,
        ] as $port) {
            if ($port !== $missing) {
                $container->instance($port, $this->stubFor($port));
            }
        }

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage($missing);

        $container->resolve(NoteService::class);
    }

    /**
     * A minimal implementation of whichever port is being placed, so the test can
     * hold two of three and leave the third missing.
     */
    private function stubFor(string $port): object
    {
        return match ($port) {
            NoteRepositoryContract::class => new class implements NoteRepositoryContract {
                public function find(int $id): ?NoteEntity
                {
                    return null;
                }

                public function findOwnedBy(int $id, int $userId): ?NoteEntity
                {
                    return null;
                }

                public function paginateForUser(int $userId, int $perPage, int $page): PaginatedResult
                {
                    return PaginatedResult::fromSlice([], 0, $perPage, $page);
                }

                public function save(NoteEntity $note): NoteEntity
                {
                    return $note;
                }

                public function delete(int $id): bool
                {
                    return false;
                }
            },
            NoteAuditContract::class => new class implements NoteAuditContract {
                public function record(NoteAuditEntry $entry): void
                {
                }

                public function forNote(int $noteId): array
                {
                    return [];
                }
            },
            TransactionManagerContract::class => new class implements TransactionManagerContract {
                public function transaction(callable $callback): mixed
                {
                    return $callback();
                }
            },
            default => throw new \InvalidArgumentException(sprintf('No stub for [%s].', $port)),
        };
    }

    /**
     * The repository is non-shared, so two resolves are two objects. That is the
     * deliberate choice in the provider — see its docblock — and it is asserted
     * so a later change to `singleton()` is a decision rather than a drift.
     */
    public function test_the_repository_is_not_shared(): void
    {
        $container = $this->app();

        $this->assertNotSame(
            $container->resolve(NoteRepositoryContract::class),
            $container->resolve(NoteRepositoryContract::class)
        );
    }

    /**
     * Injecting a `$wpdb` the container cannot see is not the container's job —
     * WordPress owns that object. What this pins is that the bound adapter is
     * constructed with no arguments, so the global-resolution path is the one
     * that runs in production.
     */
    public function test_the_adapter_is_built_without_a_database_handle(): void
    {
        /** @var WpNoteRepository $resolved */
        $resolved = $this->app()->resolve(NoteRepositoryContract::class);

        // Asking for the table name reaches for the $wpdb global, which is
        // absent here — which proves the adapter carries a null handle and
        // resolves lazily, exactly as the provider documents.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No $wpdb instance is available');

        $resolved->getTableName();
    }
}
