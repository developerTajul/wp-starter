<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

/**
 * A distinct class name for a distinct stack position.
 *
 * The pipeline resolves middleware by class, so two layers have to be two
 * classes; a subclass is enough, and subclasses are how a real application
 * configures a base middleware too.
 */
final class OuterMiddleware extends RecordingMiddleware
{
}
