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
        Schema::create('ai_improvement_suggestions', function (Blueprint $table) {
            $table->id();

            // Classification
            $table->string('type', 50); // knowledge, prompt, tool
            $table->string('category', 100)->nullable(); // e.g., 'shipping_info', 'pricing'
            $table->string('priority', 20)->default('medium'); // low, medium, high, critical

            // Status workflow
            $table->string('status', 30)->default('pending'); // pending, approved, rejected, applied, needs_development
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // The suggestion itself
            $table->string('title');
            $table->text('problem_summary');
            $table->json('suggested_changes');

            // Evidence
            $table->json('evidence_message_ids'); // Array of message IDs with negative feedback
            $table->unsignedInteger('pattern_count')->default(1);
            $table->decimal('confidence_score', 3, 2)->nullable(); // 0.00-1.00

            // Type-specific targets
            $table->foreignId('target_knowledge_id')->nullable()->constrained('ai_learned_knowledge')->nullOnDelete();
            $table->string('target_prompt_key', 100)->nullable();
            $table->string('target_tool_class')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('status');
            $table->index('type');
            $table->index('priority');
            $table->index('category');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_improvement_suggestions');
    }
};
