<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

/**
 * The innermost labelled layer. See {@see OuterMiddleware}.
 */
final class InnerMiddleware extends RecordingMiddleware
{
}
