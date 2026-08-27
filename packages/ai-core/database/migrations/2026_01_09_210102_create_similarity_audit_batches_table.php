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
        Schema::create('similarity_audit_batches', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->integer('total_items');
            $table->integer('processed_items')->default(0);
            $table->json('options');
            $table->foreignId('user_id')->constrained();
            $table->string('status');
            $table->json('issues')->nullable();
            $table->integer('issues_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['model_type', 'status']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('similarity_audit_batches');
    }
};
