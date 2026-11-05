<?php
declare(strict_types=1);

namespace Framework\Routing;

use Throwable;

/**
 * What {@see RouteLoader::load()} did, so the caller can report it.
 *
 * A result object rather than a bool plus an out-parameter, because the caller
 * genuinely has three things to distinguish and one of them is not a failure:
 *
 *  - **loaded** — the file ran and routes were collected.
 *  - **skipped** — there was nothing to load. A project with no `routes/web.php`
 *    is normal and must boot quietly; reporting it as a failure would train
 *    everyone to ignore the message that matters.
 *  - **failed** — the file exists and did not load. This is the one worth a log
 *    line, a CLI warning, and the file name in the message.
 *
 * `alreadyLoaded` is a fourth state that is *not* a failure either — it is the
 * expected outcome of the second hook firing in one request — and it is kept
 * distinct from `skipped` so a test can tell "the guard worked" from "there was
 * no file".
 */
final class RouteLoadResult
{
    private function __construct(
        public readonly bool $loaded,
        public readonly bool $skipped,
        public readonly bool $alreadyLoaded,
        public readonly ?string $path,
        public readonly ?Throwable $error,
        public readonly string $message,
        public readonly int $routeCount = 0
    ) {
    }

    public static function loaded(string $path, int $routeCount): self
    {
        return new self(
            loaded: true,
            skipped: false,
            alreadyLoaded: false,
            path: $path,
            error: null,
            message: sprintf('Loaded %d route(s) from [%s].', $routeCount, $path),
            routeCount: $routeCount
        );
    }

    public static function skipped(string $reason): self
    {
        return new self(
            loaded: false,
            skipped: true,
            alreadyLoaded: false,
            path: null,
            error: null,
            message: $reason
        );
    }

    public static function alreadyLoaded(string $path): self
    {
        return new self(
            loaded: false,
            skipped: false,
            alreadyLoaded: true,
            path: $path,
            error: null,
            message: sprintf('[%s] has already been loaded in this process.', $path)
        );
    }

    public static function failure(string $path, Throwable $error): self
    {
        return new self(
            loaded: false,
            skipped: false,
            alreadyLoaded: false,
            path: $path,
            error: $error,
            message: sprintf('Failed to load routes from [%s]: %s', $path, $error->getMessage())
        );
    }

    /**
     * Whether anything went wrong.
     *
     * `skipped` and `alreadyLoaded` are not failures — see the class docblock.
     */
    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * A message suitable for a log or a CLI warning.
     */
    public function describe(): string
    {
        return $this->message;
    }
}
