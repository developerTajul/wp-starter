<?php
declare(strict_types=1);
require_once __DIR__ . '/../stubs/wordpress.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../stubs/wp-classes.php';
require_once __DIR__ . '/../stubs/builtins.php';

$classes = [
    'Tests\Unit\Support\Fixtures\Lead',
    'Tests\Unit\Support\Fixtures\LeadResource',
    'Tests\Unit\Support\Fixtures\DetailedLeadResource',
    'Tests\Unit\Support\Fixtures\AccessorLeadResource',
    'Tests\Unit\Support\Fixtures\AnonymousResource',
    'Tests\Unit\Support\Fixtures\CompanyResource',
    'Tests\Unit\Support\Fixtures\NestingLeadResource',
    'Tests\Unit\Support\Fixtures\BrokenResource',
    'Tests\Unit\Support\Fixtures\ThrowingResource',
    'Tests\Unit\Http\Fixtures\PingRequest',
    'Tests\Unit\Http\Fixtures\RedirectingRequest',
    'Tests\Unit\Http\Fixtures\StoreLeadRequest',
];

$bad = 0;
foreach ($classes as $class) {
    $ok = class_exists($class);
    $bad += $ok ? 0 : 1;
    printf("%s %s\n", $ok ? ' ok ' : 'FAIL', $class);
}

printf("\n%s\n", $bad === 0 ? 'every fixture autoloads on its own' : "$bad fixture(s) still need another file loaded first");
exit($bad === 0 ? 0 : 1);
