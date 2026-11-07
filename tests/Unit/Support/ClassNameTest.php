<?php
declare(strict_types=1);

namespace Tests\Unit\Support;

use Framework\Support\ClassName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The parse that decides where a generated class lands.
 *
 * Kept separate from the generator tests because the rules are the *contract*
 * the generators share, and a generator test can only show them working through
 * one generator's target path. Here they are asserted directly, including the
 * separator joins — which is where the two real bugs in this feature lived: a
 * missing separator produced `ControllersAdmin` (a valid namespace, so nothing
 * complained) and a doubled one produced `Controllers\Admin\;`.
 */
final class ClassNameTest extends TestCase
{
    // ------------------------------------------------------------- the splits

    public function test_a_bare_name_has_no_sub_namespace(): void
    {
        $parsed = ClassName::parse('CompanyController');

        $this->assertSame('CompanyController', $parsed->class);
        $this->assertSame('', $parsed->sub);
        $this->assertSame('', $parsed->path);
    }

    public function test_a_slashed_name_splits_into_class_and_sub_namespace(): void
    {
        $parsed = ClassName::parse('Admin/CompanyController');

        $this->assertSame('CompanyController', $parsed->class);
        $this->assertSame('Admin', $parsed->sub);
        $this->assertSame('Admin', $parsed->path);
    }

    public function test_nested_segments_keep_their_order(): void
    {
        $parsed = ClassName::parse('Admin/Leads/CompanyController');

        $this->assertSame('CompanyController', $parsed->class);
        $this->assertSame('Admin\\Leads', $parsed->sub);
        $this->assertSame('Admin/Leads', $parsed->path);
    }

    /**
     * `\` is the namespace spelling of the same request — a developer reaching
     * for it must not get a differently-placed class.
     */
    public function test_a_backslash_is_the_same_separator(): void
    {
        $slashed = ClassName::parse('Admin/Leads/CompanyController');
        $backslashed = ClassName::parse('Admin\\Leads\\CompanyController');

        $this->assertSame($slashed->class, $backslashed->class);
        $this->assertSame($slashed->sub, $backslashed->sub);
        $this->assertSame($slashed->path, $backslashed->path);
    }

    // ------------------------------------------------------- the normalizations

    /**
     * Leading, trailing and repeated separators are all noise. A run of them
     * must not produce an empty segment, which would render as a doubled
     * separator in the path or namespace.
     */
    public function test_redundant_separators_are_collapsed(): void
    {
        foreach (['/Admin/CompanyController', 'Admin/CompanyController/', 'Admin//CompanyController'] as $input) {
            $parsed = ClassName::parse($input);

            $this->assertSame('CompanyController', $parsed->class, $input);
            $this->assertSame('Admin', $parsed->sub, $input);
            $this->assertSame('Admin/', $parsed->pathPrefix(), $input);
            $this->assertSame('Admin\\', $parsed->namespacePrefix(), $input);
        }
    }

    public function test_a_php_suffix_is_stripped(): void
    {
        $parsed = ClassName::parse('Admin/CompanyController.php');

        $this->assertSame('CompanyController', $parsed->class);
        $this->assertSame('Admin', $parsed->sub);
    }

    // --------------------------------------------------------- the join helpers

    /**
     * Both prefixes carry their own trailing separator, because every caller
     * joins them onto something and a helper that returned `Admin` would put
     * that decision back in each caller — which is exactly how
     * `ControllersAdmin` gets written.
     */
    public function test_the_prefix_helpers_carry_a_trailing_separator(): void
    {
        $parsed = ClassName::parse('Admin/Leads/CompanyController');

        $this->assertSame('Admin/Leads/', $parsed->pathPrefix());
        $this->assertSame('Admin\\Leads\\', $parsed->namespacePrefix());
    }

    /**
     * A bare name contributes nothing to either join — not a stray separator.
     * A prefix of `/` or `\` would put the class in a sub-directory named after
     * nothing.
     */
    public function test_a_bare_name_has_empty_prefixes(): void
    {
        $parsed = ClassName::parse('CompanyController');

        $this->assertSame('', $parsed->pathPrefix());
        $this->assertSame('', $parsed->namespacePrefix());
    }

    // ------------------------------------------------------------- the refusals

    /**
     * A lower-case segment is refused: it reads as a namespace and as a class
     * name equally well, so resolving it silently would put the class somewhere
     * no other file in the project spells.
     */
    public function test_a_lower_case_segment_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('upper-case');

        ClassName::parse('admin/CompanyController');
    }

    /**
     * A lower-case *class* is refused for the same reason.
     */
    public function test_a_lower_case_class_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('upper-case');

        ClassName::parse('companyController');
    }

    public function test_a_segment_that_is_not_a_label_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not usable as a class or namespace name');

        ClassName::parse('Admin/9-Bad');
    }

    public function test_a_name_of_only_separators_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClassName::parse('///');
    }

    // ------------------------------------------------------- the lenient parse

    /**
     * `parseLenient()` drops the case rule and nothing else, so the split still
     * happens — that is what lets `notes/create_leads_table` become a migration
     * in a `Notes` sub-directory.
     */
    public function test_the_lenient_parse_still_splits(): void
    {
        $parsed = ClassName::parseLenient('notes/create_leads_table');

        $this->assertSame('create_leads_table', $parsed->class);
        $this->assertSame('notes', $parsed->sub);
        $this->assertSame('notes/', $parsed->pathPrefix());
    }

    /**
     * …and accepts prose that the strict parse would reject outright. This is
     * the case the lenient path exists for: a migration description is not a
     * class name, and holding it to class-name rules would refuse input the
     * generator handles correctly.
     */
    public function test_the_lenient_parse_accepts_a_free_form_description(): void
    {
        $parsed = ClassName::parseLenient('create leads table');

        $this->assertSame('create leads table', $parsed->class);
        $this->assertSame('', $parsed->sub);
    }

    /**
     * The lenient parse is not a free-for-all: a name that is only separators
     * still has no class in it, and the generator downstream would name a file
     * `<timestamp>_.php` — which the migrator skips forever.
     */
    public function test_the_lenient_parse_still_refuses_an_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClassName::parseLenient('///');
    }
}
