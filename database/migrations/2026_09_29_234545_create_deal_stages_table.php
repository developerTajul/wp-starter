<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

final class CreateDealStagesTable extends Migration
{
    public function up(): void
    {
        Schema::create('deal_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('order_position')->default(1);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->timestamps();

            $table->unique(['pipeline_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stages');
    }
}
