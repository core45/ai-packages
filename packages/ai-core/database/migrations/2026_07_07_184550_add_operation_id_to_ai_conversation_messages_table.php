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
     * Adds a nullable `operation_id` column used to key idempotent
     * partial-message persistence (SEAM-06). Nullable with no backfill so
     * all existing rows remain byte-untouched (NULL is valid for pre-seam
     * messages). The composite unique index carries an explicit short name
     * to stay well within MySQL's 64-char identifier limit. MySQL unique
     * indexes permit multiple NULLs, so existing rows never collide.
     */
    public function up(): void
    {
        Schema::table('ai_conversation_messages', function (Blueprint $table) {
            $table->uuid('operation_id')->nullable()->after('ai_conversation_id');

            $table->unique(
                ['ai_conversation_id', 'operation_id'],
                'ai_conv_messages_conversation_operation_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_conversation_messages', function (Blueprint $table) {
            $table->dropUnique('ai_conv_messages_conversation_operation_unique');
            $table->dropColumn('operation_id');
        });
    }
};
