<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

/**
 * Run the migrations.
 *
 * The companies table indexed name but never made it unique, so two writers
 * could both store the same company and the race the write path guards
 * against had no constraint behind it. The unique index is what actually
 * decides the conflict; the adapter turns its rejection into
 * CompanyDuplicateException for the Service to report.
 *
 * The index replaces the plain one rather than sitting beside it: a unique
 * index already serves every lookup the plain index served, so keeping both
 * would only add a second B-tree to maintain on every write.
 *
 * Ported from the Laravel migration of the same name. The comparison follows
 * the column's collation, `utf8mb4_unicode_ci` on a standard install, so `Acme`
 * and `acme` collide — a name identifies a company however it was typed.
 *
 * Applying this refuses an install that already holds duplicate names: the
 * statement fails and the migrator reports it, rather than the duplicates being
 * removed. Deleting customer rows is not this migration's decision to make.
 */
final class MakeCompaniesNameUnique extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restores the plain index the unique one replaced, so a rollback leaves
     * the table as the original migration created it.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->index('name');
        });
    }
}
