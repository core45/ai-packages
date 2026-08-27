<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_gaps', function (Blueprint $table) {
            $table->id();

            // The question/gap
            $table->text('question');
            $table->text('ai_response')->nullable();
            $table->decimal('confidence_score', 3, 2)->default(0.00);

            // Context
            $table->enum('audience', ['public', 'customer', 'admin'])->default('public');
            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained('ai_conversations')
                ->nullOnDelete();

            // Tracking
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('last_occurred_at')->nullable();
            $table->json('similar_questions')->nullable();

            // Resolution
            $table->enum('status', ['open', 'resolved', 'ignored'])->default('open');
            $table->foreignId('resolved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolution_knowledge_id')
                ->nullable()
                ->constrained('ai_learned_knowledge')
                ->nullOnDelete();

            $table->timestamps();

            // Indexes
            $table->index('status');
            $table->index('audience');
            $table->index('occurrence_count');
            $table->index('confidence_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_gaps');
    }
};
