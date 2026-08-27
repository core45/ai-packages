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
        Schema::create('translation_locks', function (Blueprint $table) {
            $table->id();
            $table->morphs('lockable');
            $table->string('locale', 10);
            $table->uuid('lock_token');
            $table->json('snapshot')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['lockable_type', 'lockable_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translation_locks');
    }
};
