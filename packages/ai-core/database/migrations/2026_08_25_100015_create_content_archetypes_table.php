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
        Schema::create('content_archetypes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('applicable_model');
            $table->text('description')->nullable();

            $table->text('system_prompt');
            $table->json('sections_config');

            $table->string('tone')->nullable();
            $table->string('formality')->nullable();
            $table->json('forbidden_words')->nullable();
            $table->json('mandatory_words')->nullable();
            $table->json('style_rules')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['applicable_model', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_archetypes');
    }
};
