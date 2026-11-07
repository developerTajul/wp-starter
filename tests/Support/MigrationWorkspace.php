<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * A throwaway directory of migration files.
 *
 * Migration files are named after the class they declare, so a test cannot
 * simply `eval` a class into existence — it has to write a file whose name and
 * contents agree, exactly as a real project would. This wraps that in one call
 * and cleans the directory up afterwards.
 *
 * Files are written into the system temp directory rather than the plugin tree,
 * so a failing test can never leave a stray migration behind that a later run
 * would silently pick up.
 */
final class MigrationWorkspace
{
    private string $path;

    /**
     * The namespace this workspace's generated migrations declare.
     *
     * Unique per workspace, and this is load-bearing. PHP cannot undeclare a
     * class, and the migrator `require`s a migration by *path* — so two tests
     * that both write `2026_11_05_100000_create_users_table.php` in different
     * temp directories would each declare `...\CreateUsersTable`, and the second
     * require would be a fatal "cannot declare class, name already in use".
     *
     * A namespace per workspace fixes that without weakening the test. The
     * filenames and the class-name derivation stay exactly what a real project
     * produces; only the namespace differs, and the migrator is indifferent to
     * it (it loads by path and checks the short class name the filename implies).
     */
    private string $namespace;

    public function __construct()
    {
        $suffix = bin2hex(random_bytes(6));

        $this->path = sys_get_temp_dir() . '/wpml-migrations-' . $suffix;
        $this->namespace = 'Tests\Support\Generated\W' . $suffix;

        if (!is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }
    }

    /**
     * The namespace generated migrations are declared in.
     */
    public function namespace(): string
    {
        return $this->namespace;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Write a migration whose body mirrors the given template class.
     *
     * The template's fully-qualified name is rewritten to the StudlyCase class
     * the filename implies, and its namespace rewritten to
     * {@see self::NAMESPACE}, so one template can produce any number of
     * differently-named migrations.
     *
     * The class name is derived by handing the identifier to the framework's own
     * {@see \Framework\Database\MigrationFile}, so the test agrees with the
     * migrator about the convention by construction — a second implementation
     * here could drift and make a broken rule look tested.
     *
     * @param class-string $template
     */
    public function write(string $identifier, string $template): string
    {
        $parsed = \Framework\Database\MigrationFile::fromIdentifier(
            $identifier,
            $this->path . '/' . $identifier . '.php'
        );

        $class = $parsed->class;

        $source = (string) file_get_contents((new \ReflectionClass($template))->getFileName());

        // The template declares its own class name and namespace; both are
        // replaced so the copy genuinely declares what the filename promises.
        $shortName = (new \ReflectionClass($template))->getShortName();

        $source = preg_replace('/^namespace .+;$/m', 'namespace ' . $this->namespace . ';', $source, 1) ?? $source;
        $source = preg_replace(
            '/\bclass\s+' . preg_quote($shortName, '/') . '\b/',
            'class ' . $class,
            $source,
            1
        ) ?? $source;

        return $this->writeRaw($identifier . '.php', $source);
    }

    /**
     * Write a file verbatim, for the cases where the content itself is what is
     * under test — a malformed class, a file that is not a migration at all.
     */
    public function writeRaw(string $filename, string $contents): string
    {
        $target = $this->path . '/' . $filename;

        file_put_contents($target, $contents);

        return $target;
    }

    public function delete(string $identifier): void
    {
        $file = $this->path . '/' . $identifier . '.php';

        if (is_file($file)) {
            unlink($file);
        }
    }

    public function cleanup(): void
    {
        if (!is_dir($this->path)) {
            return;
        }

        // Recursive, because a test may legitimately have had the workspace write
        // into a subdirectory — `migrate:dump --path=dumps/schema.php` creates one
        // — and a shallow unlink leaves it behind. `rmdir()` then fails on a
        // non-empty directory, which surfaces as a PHPUnit warning on a test that
        // passed. Removing the tree is the honest fix: the workspace's contract is
        // that its directory is gone afterwards.
        $this->removeDirectory($this->path);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                $this->removeDirectory($entry);

                continue;
            }

            unlink($entry);
        }

        rmdir($directory);
    }
}
