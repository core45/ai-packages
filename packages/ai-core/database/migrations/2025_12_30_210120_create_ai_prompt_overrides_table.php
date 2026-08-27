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
        Schema::create('ai_prompt_overrides', function (Blueprint $table) {
            $table->id();

            $table->string('prompt_key', 100); // e.g., 'admin_system', 'customer_system'
            $table->text('override_content'); // Additional prompt content to append
            $table->string('change_type', 30)->default('append'); // append, prepend, replace_section
            $table->foreignId('suggestion_id')->nullable()->constrained('ai_improvement_suggestions')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->text('description')->nullable(); // Human-readable description of what this override does

            $table->timestamps();

            // Indexes
            $table->index('prompt_key');
            $table->index('enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_overrides');
    }
};
