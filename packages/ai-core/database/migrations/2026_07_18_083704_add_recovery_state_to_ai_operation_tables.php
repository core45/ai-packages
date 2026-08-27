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
        Schema::table('ai_detections', function (Blueprint $table) {
            $table->longText('request_text')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->char('active_intent_key', 64)->nullable()->unique('ai_detections_active_intent_unique');
            $table->string('submission_state', 20)->default('accepted');
            $table->longText('humanize_continuation_payload')->nullable();
            $table->unsignedSmallInteger('poll_failures')->default(0);
            $table->timestamp('last_poll_failed_at')->nullable();
        });

        Schema::table('ai_humanizations', function (Blueprint $table) {
            $table->char('request_hash', 64)->nullable();
            $table->char('active_intent_key', 64)->nullable()->unique('ai_humanizations_active_intent_unique');
            $table->string('submission_state', 20)->default('accepted');
            $table->json('resolved_options')->nullable();
            $table->timestamp('written_back_at')->nullable();
            $table->unsignedSmallInteger('poll_failures')->default(0);
            $table->timestamp('last_poll_failed_at')->nullable();
        });

        Schema::create('ai_operation_continuations', function (Blueprint $table) {
            $table->id();
            $table->morphs('operation');
            $table->char('continuation_key', 64)->unique();
            $table->longText('payload');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['released_at', 'dispatched_at'], 'ai_operation_continuations_release_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_operation_continuations');

        Schema::table('ai_detections', function (Blueprint $table) {
            $table->dropUnique('ai_detections_active_intent_unique');
            $table->dropColumn([
                'request_text',
                'request_hash',
                'active_intent_key',
                'submission_state',
                'humanize_continuation_payload',
                'poll_failures',
                'last_poll_failed_at',
            ]);
        });

        Schema::table('ai_humanizations', function (Blueprint $table) {
            $table->dropUnique('ai_humanizations_active_intent_unique');
            $table->dropColumn([
                'request_hash',
                'active_intent_key',
                'submission_state',
                'resolved_options',
                'written_back_at',
                'poll_failures',
                'last_poll_failed_at',
            ]);
        });
    }
};
