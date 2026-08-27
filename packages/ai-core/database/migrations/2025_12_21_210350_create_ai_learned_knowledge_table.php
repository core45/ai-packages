<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_learned_knowledge', function (Blueprint $table) {
            $table->id();

            // Classification
            $table->enum('type', [
                'instruction',  // Custom instructions (E) - rules like "Always be formal"
                'fact',         // Business facts (B) - office hours, policies
                'faq',          // FAQ entries
            ])->default('fact');
            $table->enum('tier', ['critical', 'contextual'])->default('contextual');
            $table->enum('audience', ['public', 'customer', 'admin'])->default('public');

            // Content
            $table->string('title');
            $table->text('content');
            $table->json('keywords')->nullable();

            // Source tracking
            $table->enum('source_type', [
                'manual',       // Super-admin created via UI
                'suggested',    // AI suggested, admin confirmed
            ])->default('manual');

            // Status
            $table->boolean('enabled')->default(true);

            // Ownership
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Usage tracking
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);

            $table->timestamps();

            // Indexes
            $table->index('type');
            $table->index('tier');
            $table->index('audience');
            $table->index('enabled');
            $table->index(['tier', 'audience', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_learned_knowledge');
    }
};
