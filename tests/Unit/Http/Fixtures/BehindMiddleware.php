<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

/**
 * Sits behind a short circuit in the stack, so it should never be resolved.
 *
 * Kept as its own class rather than reused as a second {@see RecordingMiddleware}
 * entry: the point of the test is that the container is never asked to build
 * this, and a factory bound to the same class as its neighbour could not tell
 * the two positions apart.
 */
final class BehindMiddleware extends RecordingMiddleware
{
}
