<?php
declare(strict_types=1);

namespace Framework\Routing;

/**
 * Where {@see ParameterBinder} reads a request's values from.
 *
 * The binder knows *how* to fill a controller action's parameters — name
 * matching, bool coercion, type checking, placeholder precedence. It does not
 * know what it is reading, and this is the seam that keeps it that way: a REST
 * request is read through `WP_REST_Request`, a web request through
 * `Framework\Http\Request`, and neither class appears in the binder.
 *
 * Two methods, because the binder genuinely needs two different things. Route
 * parameters are read as a whole map so a placeholder can be checked for
 * presence by name and preferred over a body value of the same name — the
 * precedence that stops a client from addressing one record through the URL and
 * acting on another through the body.
 */
interface ParameterSource
{
    /**
     * Every parameter the URL matched, keyed by placeholder name.
     *
     * Returned as a map rather than looked up one name at a time because the
     * binder has to distinguish "matched as the empty string" from "not matched
     * at all", and only the map can express that.
     *
     * @return array<string, mixed>
     */
    public function routeParameters(): array;

    /**
     * The value carried under $name, or null when there is none.
     *
     * Null means absent, not "present and null" — a request cannot distinguish
     * the two, and the binder treats absent by leaving the argument out so the
     * parameter's own default applies.
     *
     * @return mixed
     */
    public function value(string $name): mixed;
}
