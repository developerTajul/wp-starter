<?php
declare(strict_types=1);

namespace Framework\Database;

use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Framework\Database\Query\QueryBuilderInterface;
use Framework\Database\Query\WpFluentQueryBuilder;

/**
 * Binds the fluent query builder.
 *
 * One binding, and the word "builder" is doing the work in it: the container
 * hands out the *factory*, not a query. A query is a value that a repository
 * derives by chaining, and every chain step returns a new builder - so the
 * object bound here is the empty starting point, and two repositories that
 * resolve it are two independent chains rather than two views of one.
 *
 * Why `bind()` and not `singleton()`
 *
 * Every other binding in the migration provider is a singleton, and this one is
 * deliberately not. A singleton builder would be **one shared starting point**
 * handed to every caller, which is safe only as long as nobody can move it - and
 * the builder is immutable, so nobody can. That makes the two bindings behave
 * identically today.
 *
 * They would not behave identically under a change. `bind()` gives each caller
 * its own instance, so a future non-immutable method (a `reset()`, a cached
 * compilation) could not leak between callers; a singleton would make that leak
 * a cross-request bug, appearing in whichever repository happened to run second.
 * The cost of the safer binding is one object allocation per resolution, and the
 * builder holds nothing but scalars and arrays.
 *
 * Why the `$wpdb` argument is omitted
 *
 * The builder resolves the global lazily, on its first statement - see
 * {@see WpFluentQueryBuilder}. Passing `$GLOBALS['wpdb']` here would capture
 * whatever exists during `register()`, which is before WordPress has finished
 * loading it, and would pin the builder to that connection. Lazy resolution also
 * means the binding is safe to register in a test with no WordPress at all,
 * which is what lets a unit test resolve this provider's container and hand the
 * builder a fake.
 */
class QueryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->bind(
            QueryBuilderInterface::class,
            static fn (): QueryBuilderInterface => new WpFluentQueryBuilder()
        );
    }
}
