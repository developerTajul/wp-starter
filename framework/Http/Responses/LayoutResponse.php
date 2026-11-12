<?php
declare(strict_types=1);

namespace Framework\Http\Responses;

use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Exceptions\HttpException;
use Framework\Support\ViewRenderer;

/**
 * A view response whose body is wrapped in the application layout.
 *
 * Built by {@see ViewRenderer::make()} whenever the requested view differs from
 * the configured layout. The child is rendered first into a string (`$content`),
 * then handed to the layout template as its `$content` variable; the layout's
 * `$title` comes from the view's data or is derived from the view's path. The
 * whole wrapping happens in {@see send()}, so a controller returns the value
 * immediately and the layout stays behind the scenes — Laravel's `@extends`
 * without a second template language.
 *
 * Flash notices, validation errors and old input are re-injected by the
 * renderer when the layout is rendered, so the layout's own error/notice blocks
 * work transparently.
 */
final class LayoutResponse implements ResponseInterface
{
    private bool $sent = false;

    public function __construct(
        private readonly ViewResponse $child,
        private readonly ViewRenderer $viewRenderer,
        private readonly string $layout,
        private readonly string $viewName
    ) {
    }

    /**
     * Send the layout-wrapped body.
     *
     * Headers are sent before the body: the child may have thrown while being
     * rendered, and in that case nothing has been emitted, so the caller still
     * owns the response. The child's own status and headers are forwarded.
     *
     * @throws HttpException When the child or the layout fails to render.
     */
    public function send(): void
    {
        if ($this->sent) {
            throw HttpException::alreadySent(self::class);
        }

        $this->sent = true;

        $body = $this->render();

        $this->sendHeaders();

        echo $body;
    }

    /**
     * Render the child and wrap it in the layout, without sending anything.
     *
     * Keeps the response lazy: nothing is computed until the body is actually
     * needed. Also what a test calls to assert the full page.
     *
     * @throws HttpException When the child or the layout fails to render.
     */
    public function render(): string
    {
        $content = $this->child->render();

        $data = $this->child->data();

        $title = $data['title'] ?? $this->deriveTitle();

        return $this->viewRenderer->renderPath($this->layout, [
            'title' => $title,
            'content' => $content,
        ]);
    }

    public function status(): int
    {
        return $this->child->status();
    }

    /**
     * Override the page title.
     *
     * Returns a new instance so a title can be chained after {@see view()}
     * instead of being mixed into the view's data. The layout reads the title
     * from the child's data if present; otherwise it is derived from the view
     * path.
     */
    public function withTitle(string $title): static
    {
        return new self($this->child->withTitle($title), $this->viewRenderer, $this->layout, $this->viewName);
    }

    /**
     * The unwrapped child response.
     *
     * Exposed mainly for tests that need to assert on the child separately from
     * the wrapped page.
     */
    public function child(): ViewResponse
    {
        return $this->child;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->child->headers();
    }

    /**
     * Derive a page title from the view's name: each segment is title-cased and
     * joined with spaces. `company.create` -> "Company Create";
     * `dashboard` -> "Dashboard".
     */
    private function deriveTitle(): string
    {
        $name = preg_replace('/\.php$/i', '', $this->viewName) ?? $this->viewName;

        $segments = array_filter(
            explode('/', str_replace('.', '/', $name)),
            static fn (string $segment): bool => $segment !== ''
        );

        return implode(' ', array_map(
            fn (string $segment): string => $this->titleCase($segment),
            $segments
        ));
    }

    /**
     * Title-case a single segment, turning `snake_case` and `kebab-case` into
     * spaced words.
     */
    private function titleCase(string $segment): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $segment));
    }

    /**
     * Emit the status line and headers.
     *
     * Guarded on `headers_sent()` because the body may have already begun to
     * reach the client; calling the header functions anyway produces a warning.
     */
    private function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        if ($this->status() !== 200 && function_exists('status_header')) {
            status_header($this->status());
        }

        foreach ($this->headers() as $name => $value) {
            header(sprintf('%s: %s', $name, $value), true);
        }
    }
}
