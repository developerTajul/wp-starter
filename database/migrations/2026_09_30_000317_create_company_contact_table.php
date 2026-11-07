<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

final class CreateCompanyContactTable extends Migration
{
    public function up(): void
    {
        Schema::create('company_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('job_title')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_contact');
    }
}
