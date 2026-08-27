<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convert ENUM to VARCHAR for flexibility
        // This allows adding new types without migrations
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE ai_learned_knowledge MODIFY COLUMN type VARCHAR(50) NOT NULL DEFAULT 'fact'");
        } elseif ($driver === 'sqlite') {
            // SQLite enum() creates a CHECK constraint that restricts values.
            // Recreate the column without the CHECK to allow new types.
            Schema::table('ai_learned_knowledge', function (Blueprint $table) {
                $table->string('type', 50)->default('fact')->change();
            });
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Convert back to ENUM (without new types)
            DB::statement("ALTER TABLE ai_learned_knowledge MODIFY COLUMN type ENUM('instruction', 'fact', 'faq') NOT NULL DEFAULT 'fact'");
        }
    }
};
