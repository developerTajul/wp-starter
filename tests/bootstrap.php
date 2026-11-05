<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Order matters. The WordPress stubs are loaded first, before the Composer
 * autoloader, because the framework's helper file is registered as an
 * `autoload.files` entry and executes the moment the autoloader is built —
 * it guards its own declarations with function_exists(), and those guards need
 * the stubs (and any test double) to have had the chance to define first.
 */

require_once __DIR__ . '/stubs/wordpress.php';

require_once dirname(__DIR__) . '/vendor/autoload.php';

// After the autoloader, so a real WordPress install loaded into this process —
// were one ever loaded — would define these first and win.
require_once __DIR__ . '/stubs/wp-classes.php';

// Doubles for PHP *builtins*, which cannot be declared globally and so cannot
// live in wordpress.php. Required last: it declares a function in the framework's
// own namespace, and the framework's files must have been resolvable first.
require_once __DIR__ . '/stubs/builtins.php';
