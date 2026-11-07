<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

final class CreateDealsTable extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0.00);
            $table->string('currency', 3)->default('BDT')->index();
            $table->foreignId('pipeline_id')->constrained()->restrictOnDelete();
            $table->foreignId('deal_stage_id')->constrained()->restrictOnDelete();
            $table->date('expected_close_date')->nullable();
            $table->date('actual_close_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
}
