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
        Schema::table('ai_conversation_messages', function (Blueprint $table) {
            // Feedback rating: positive (thumbs up), negative (thumbs down), or null (no feedback)
            $table->string('feedback_rating', 20)->nullable()->after('metadata');
            // Reason for negative feedback (optional text from admin)
            $table->text('feedback_reason')->nullable()->after('feedback_rating');
            // When feedback was given
            $table->timestamp('feedback_at')->nullable()->after('feedback_reason');
            // Auto-flagged error (for tracking errors that occurred during AI response)
            $table->boolean('has_error')->default(false)->after('feedback_at');
            // Error type for categorization (e.g., context_length_exceeded, timeout, etc.)
            $table->string('error_type', 100)->nullable()->after('has_error');

            // Index for finding messages with feedback for analysis
            $table->index('feedback_rating');
            $table->index('has_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_conversation_messages', function (Blueprint $table) {
            $table->dropIndex(['feedback_rating']);
            $table->dropIndex(['has_error']);
            $table->dropColumn([
                'feedback_rating',
                'feedback_reason',
                'feedback_at',
                'has_error',
                'error_type',
            ]);
        });
    }
};
