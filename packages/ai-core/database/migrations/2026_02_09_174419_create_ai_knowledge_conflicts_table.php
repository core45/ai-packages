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
        Schema::create('ai_knowledge_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_a_id')->constrained('ai_learned_knowledge')->cascadeOnDelete();
            $table->foreignId('knowledge_b_id')->constrained('ai_learned_knowledge')->cascadeOnDelete();
            $table->unsignedTinyInteger('conflict_type');
            $table->json('overlap_keywords');
            $table->decimal('overlap_score', 5, 2)->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_a_id', 'knowledge_b_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_conflicts');
    }
};
