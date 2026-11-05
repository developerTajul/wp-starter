<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Framework\Http\Middleware\Authorize;

/**
 * Records which capabilities were asked about.
 *
 * `current_user_can()` in the stub answers the same way for everything, so the
 * only way to distinguish all-of from any-of is to count the questions: an
 * any-of implementation stops at the first yes.
 */
final class CountingAuthorize extends Authorize
{
    /**
     * @param array<int, string> $capabilities
     * @param list<string>       $asked        Written through, because it is the
     *                                         test's record of what was consulted.
     */
    public function __construct(array $capabilities, private array &$asked)
    {
        parent::__construct($capabilities);
    }

    protected function holds(string $capability): bool
    {
        $this->asked[] = $capability;

        return parent::holds($capability);
    }
}
