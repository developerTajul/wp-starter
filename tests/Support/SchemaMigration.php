<?php
declare(strict_types=1);

namespace Tests\Support;

use Framework\Database\Migration;
use Framework\Database\Schema\Schema;

/**
 * A migration that actually builds a table.
 *
 * {@see RecordingMigration} proves a direction was called; it issues no SQL, so
 * it cannot prove what statement a direction *would* have issued. `--pretend`
 * is a feature about exactly that — the compiled SQL — so the tests behind it
 * need a migration whose body goes through the schema builder.
 *
 * Used as a template by {@see MigrationWorkspace} in the same way: the class
 * name and namespace are rewritten to match the filename, and the body is left
 * alone. It creates a single table with no foreign key, so the recorded output
 * is one `CREATE TABLE` and a test can assert on it without also pinning the
 * adapter's constraint lift-out, which has its own tests.
 */
final class SchemaMigration extends Migration
{
    public function up(): void
    {
        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
}
