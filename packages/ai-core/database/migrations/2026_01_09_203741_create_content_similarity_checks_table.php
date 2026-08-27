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
        Schema::create('content_similarity_checks', function (Blueprint $table) {
            $table->id();
            $table->morphs('checkable');                    // Product, Post, etc.
            $table->string('field');                        // 'description', 'summary'
            $table->string('locale');                       // 'en', 'pl', etc.
            $table->string('compared_with_type');           // 'internal', 'external_url'
            $table->string('compared_with_reference');      // Product ID or URL
            $table->decimal('similarity_score', 5, 4);      // 0.0000 - 1.0000
            $table->string('status');                       // 'pass', 'warning', 'fail'
            $table->json('details')->nullable();            // shingles matched, etc.
            $table->timestamps();

            $table->index(['checkable_type', 'checkable_id', 'field'], 'sim_checks_checkable_field_idx');
            $table->index(['status'], 'sim_checks_status_idx');
            $table->index(['similarity_score'], 'sim_checks_score_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_similarity_checks');
    }
};
