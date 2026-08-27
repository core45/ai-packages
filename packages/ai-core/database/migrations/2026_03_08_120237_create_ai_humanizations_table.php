<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_humanizations', function (Blueprint $table) {
            $table->id();
            $table->string('humanizable_type');
            $table->unsignedBigInteger('humanizable_id');
            $table->string('locale', 10);
            $table->string('field', 50);
            $table->longText('original_text');
            $table->longText('humanized_text')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('external_id')->nullable();
            $table->timestamps();

            $table->index(['humanizable_type', 'humanizable_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_humanizations');
    }
};
