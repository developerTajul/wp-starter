<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The pipelines table marks a default row with is_default, but nothing ever
     * enforced that only one row may carry it, so three did (ids 1, 2 and 3).
     * The Deal module's conversion listener reads that flag to decide which
     * pipeline a new deal belongs to, and an ambiguous flag makes the answer
     * arbitrary.
     *
     * The lowest id keeps the flag: pipeline 1 is the original, it carries the
     * full six-stage set, and it is the one with deals attached. The others are
     * abandoned pipelines with no deals, so clearing their flag moves nothing.
     */
    public function up(): void
    {
        $keeperId = DB::table('pipelines')->where('is_default', true)->min('id');

        if ($keeperId === null) {
            return;
        }

        DB::table('pipelines')
            ->where('is_default', true)
            ->where('id', '!=', $keeperId)
            ->update(['is_default' => false, 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     *
     * The rows this cleared are not recorded, and restoring the flag on guessed
     * ids would invent state that never existed, so the reverse does nothing.
     */
    public function down(): void
    {
        // Intentionally empty: see the docblock above.
    }
};
