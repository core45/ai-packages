<?php

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
        Schema::create('ai_conversation_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('summary_date')->index();
            $table->enum('period_type', ['daily', 'weekly', 'monthly'])->default('daily');
            $table->string('locale', 5)->nullable()->index();
            $table->unsignedInteger('conversation_count')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('user_message_count')->default(0);
            $table->json('top_topics')->nullable();
            $table->json('common_questions')->nullable();
            $table->text('ai_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['summary_date', 'period_type', 'locale'], 'unique_summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_summaries');
    }
};
