<?php
declare(strict_types=1);

namespace Framework\Console\Commands;

use Framework\Config\Config;
use Framework\Console\GeneratorCommand;

/**
 * `wp wp_mini make:request StoreCompanyRequest` - scaffold a form request.
 *
 * Why this exists
 *
 * `make:controller Admin/CompanyController --resource` generates handlers whose
 * signatures name `StoreCompanyRequest` and `UpdateCompanyRequest`. Those names
 * are the right ones - they are what the project's own controllers use - but the
 * controller generator writes one file and cannot write them, so before this
 * command they were two classes every project hand-wrote from a neighbouring
 * example.
 *
 * A form request is a small, highly patterned class: a `rules()` method, and
 * nothing else that varies between one and the next. That is exactly the shape
 * worth generating, and exactly the shape where hand-writing drifts - one request
 * that forgot `nullable`, one that forgot `attributes()`.
 *
 * Why it lives beside `make:provider` rather than near the validation code
 *
 * Every generator is in this directory and extends {@see GeneratorCommand}, which
 * owns the naming rules, the stub lookup, the overwrite guard and the write. The
 * alternative - a generator inside `Framework\Http\Requests` - would either
 * duplicate that machinery or reach across the framework for it, and the class it
 * generates is a *project* class under `app/`, not a framework one.
 *
 * What it deliberately does not do
 *
 * No `--rules=` flag. A rule set is the one thing about a form request that is
 * specific to the form, it is the line the author is going to edit first, and a
 * command-line mini-language for it would be a second way to write rules that has
 * to be kept in step with the first. The stub ships the rules commented with an
 * example, and the author writes the three lines they actually mean.
 */
class MakeRequestCommand extends GeneratorCommand
{
    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' make:request';
    }

    public function getDescription(): string
    {
        return 'Create a new form request class.';
    }

    protected function getType(): string
    {
        return 'Request';
    }

    /**
     * Form requests live beside the resources and middleware they are used from,
     * not beside the controllers they are handed to.
     */
    protected function getNamespace(): string
    {
        return config('app.namespace', Config::DEFAULT_NAMESPACE) . '\\Http\\Requests';
    }

    protected function getStubPath(): string
    {
        // A bare name, so StubLocator prefers the project's stubs/request.stub
        // and falls back to the framework's, which is what keeps this working
        // when the framework is installed under vendor/.
        return 'request.stub';
    }

    protected function getTargetPath(string $className): string
    {
        // `Admin/StoreCompanyRequest` lands in app/Http/Requests/Admin/, with the
        // matching namespace appended by the base class. See subDirectoryPath().
        return app_path('Http/Requests/' . $this->subDirectoryPath() . $className . '.php');
    }

    protected function getPostWriteHint(): ?string
    {
        return 'Type-hint it on a controller action and the binder validates before the action runs.';
    }
}
