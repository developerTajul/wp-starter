<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Http\Requests\FormRequest;
use WP_REST_Request;

/**
 * A REST controller that reports what the marker was while it ran.
 *
 * The marker is a static on {@see FormRequest}, so a controller cannot read it
 * through an injected collaborator — it records it into a static of its own that
 * the test reads back. That indirection is the price of testing a static flag,
 * and it is cheaper than the alternative of testing it only through its effect on
 * a response shape.
 */
final class MarkerProbeController
{
    public static ?bool $observed = null;

    public static bool $throw = false;

    public function probe(WP_REST_Request $request): array
    {
        self::$observed = FormRequest::$restDispatch;

        if (self::$throw) {
            throw new \RuntimeException('boom');
        }

        return ['observed' => self::$observed];
    }

    public static function reset(): void
    {
        self::$observed = null;
        self::$throw = false;
    }
}
