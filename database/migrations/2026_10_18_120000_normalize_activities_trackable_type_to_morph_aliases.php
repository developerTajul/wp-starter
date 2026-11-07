<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;

/**
 * Ports a Laravel data migration that rewrote the stored values of
 * `activities.trackable_type` from fully-qualified class names to morph aliases.
 *
 * There is no query builder in this framework yet, so there is nothing to run.
 * The migration is kept as a no-op rather than deleted so that migration
 * history and batch ordering stay identical to the Laravel project it was
 * ported from.
 */
final class NormalizeActivitiesTrackableTypeToMorphAliases extends Migration
{
    public function up(): void
    {
        // No-op: see the class docblock.
    }

    public function down(): void
    {
        // No-op: see the class docblock.
    }
}
