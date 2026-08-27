<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            // Add audience column to distinguish admin, customer, and anonymous conversations
            $table->enum('audience', ['admin', 'customer', 'anonymous'])
                ->default('admin')
                ->after('status');

            // Add session_id for anonymous users (who don't have user_id)
            $table->string('session_id', 64)->nullable()->after('audience');

            // Add index for audience-based queries
            $table->index('audience');
            $table->index('session_id');
        });

        // Make user_id nullable to support anonymous conversations
        // We need to drop the foreign key first, modify, then re-add
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        // Update existing conversations to be 'admin' audience
        DB::table('ai_conversations')
            ->whereNull('audience')
            ->update(['audience' => 'admin']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore user_id to not nullable
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        // Delete anonymous conversations (they have no user_id)
        DB::table('ai_conversations')->whereNull('user_id')->delete();

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex(['audience']);
            $table->dropIndex(['session_id']);
            $table->dropColumn(['audience', 'session_id']);
        });
    }
};
