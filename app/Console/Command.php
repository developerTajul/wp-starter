<?php
declare(strict_types=1);

namespace App\Console;

use Framework\Console\Command as BaseCommand;

/**
 * Base class for this project's own commands.
 *
 * Exists so generated commands in App\Console\Commands extend something inside
 * the application namespace: project-wide output conventions, or a helper like
 * `ask()`, belong here rather than in the framework, which must stay generic.
 */
abstract class Command extends BaseCommand
{
}
