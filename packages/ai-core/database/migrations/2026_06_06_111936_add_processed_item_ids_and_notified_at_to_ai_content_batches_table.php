<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convert enum('status') to string('status', 50) to support new statuses
        // (finalizing, completed_with_errors) without another migration.
        if (DB::getDriverName() === 'sqlite') {
            // SQLite doesn't support ALTER COLUMN; use raw SQL to copy data through a temp table.
            DB::statement('CREATE TABLE ai_content_batches_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                batchable_type VARCHAR(255) NOT NULL,
                total_items INTEGER NOT NULL,
                processed_items INTEGER NOT NULL DEFAULT 0,
                failed_items INTEGER NOT NULL DEFAULT 0,
                item_ids TEXT NOT NULL,
                failed_item_ids TEXT,
                processed_item_ids TEXT,
                error_messages TEXT,
                media_options TEXT,
                status VARCHAR(50) NOT NULL DEFAULT \'pending\',
                notified_at TIMESTAMP,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');

            DB::statement('INSERT INTO ai_content_batches_new (id, user_id, batchable_type, total_items, processed_items, failed_items, item_ids, failed_item_ids, error_messages, media_options, status, created_at, updated_at)
                SELECT id, user_id, batchable_type, total_items, processed_items, failed_items, item_ids, failed_item_ids, error_messages, media_options, status, created_at, updated_at
                FROM ai_content_batches');

            Schema::drop('ai_content_batches');
            DB::statement('ALTER TABLE ai_content_batches_new RENAME TO ai_content_batches');

            DB::statement('CREATE INDEX ai_content_batches_batchable_type_index ON ai_content_batches (batchable_type)');
            DB::statement('CREATE INDEX ai_content_batches_status_index ON ai_content_batches (status)');
        } else {
            // MySQL/MariaDB/PostgreSQL: alter the column type directly
            Schema::table('ai_content_batches', function (Blueprint $table) {
                $table->string('status', 50)->default('pending')->change();
                $table->json('processed_item_ids')->nullable()->after('failed_item_ids');
                $table->timestamp('notified_at')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE TABLE ai_content_batches_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                batchable_type VARCHAR(255) NOT NULL,
                total_items INTEGER NOT NULL,
                processed_items INTEGER NOT NULL DEFAULT 0,
                failed_items INTEGER NOT NULL DEFAULT 0,
                item_ids TEXT NOT NULL,
                failed_item_ids TEXT,
                error_messages TEXT,
                media_options TEXT,
                status VARCHAR(50) NOT NULL DEFAULT \'pending\' CHECK(status IN (\'pending\',\'processing\',\'completed\',\'failed\')),
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');

            DB::statement('INSERT INTO ai_content_batches_old (id, user_id, batchable_type, total_items, processed_items, failed_items, item_ids, failed_item_ids, error_messages, media_options, status, created_at, updated_at)
                SELECT id, user_id, batchable_type, total_items, processed_items, failed_items, item_ids, failed_item_ids, error_messages, media_options, status, created_at, updated_at
                FROM ai_content_batches');

            Schema::drop('ai_content_batches');
            DB::statement('ALTER TABLE ai_content_batches_old RENAME TO ai_content_batches');

            DB::statement('CREATE INDEX ai_content_batches_batchable_type_index ON ai_content_batches (batchable_type)');
            DB::statement('CREATE INDEX ai_content_batches_status_index ON ai_content_batches (status)');
        } else {
            Schema::table('ai_content_batches', function (Blueprint $table) {
                $table->dropColumn(['processed_item_ids', 'notified_at']);
            });
        }
    }
};
