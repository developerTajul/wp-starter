<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

/**
 * Squashed baseline: this file now carries the columns and constraints that
 * later migrations added one at a time, so a fresh database gets the final
 * shape in one step. The absorbed migrations are:
 *
 *  - 2026_10_23_120000_add_unique_indexes_to_leads_table
 *  - 2026_10_28_210000_add_assigned_to_to_leads_table
 *
 * `email` and `phone` are declared `->unique()` alone. The original file
 * chained `->unique()->index()`, which compiles to a single NON-unique
 * index, so uniqueness was only ever enforced in the application; that is
 * why a separate migration existed to repair it. Declaring it correctly
 * here removes the need for that repair.
 *
 * `assigned_to` is nullable because a lead arriving from a form or a
 * webhook has no owner until somebody claims it, and `nullOnDelete` so
 * removing a user frees their leads rather than deleting them.
 */
final class CreateLeadsTable extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('company_name')->nullable();
            $table->string('status')->default('new')->index();
            $table->string('source')->index();

            // No ->after() here: that modifier only compiles inside ALTER
            // TABLE, and this column is declared at create time.
            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
}
