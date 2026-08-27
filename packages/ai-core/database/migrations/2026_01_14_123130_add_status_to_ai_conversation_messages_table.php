<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'status' column for streaming state tracking.
     * Status values: 'pending', 'streaming', 'complete', 'error'
     * Uses VARCHAR (not ENUM) per project standards - allows adding new values without migrations.
     */
    public function up(): void
    {
        Schema::table('ai_conversation_messages', function (Blueprint $table) {
            $table->string('status', 50)->default('complete')->after('content');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_conversation_messages', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
