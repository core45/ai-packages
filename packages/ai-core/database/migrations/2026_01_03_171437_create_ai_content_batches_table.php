<?php

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
        Schema::create('ai_content_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Polymorphic type: a morph alias, or the consuming app's own model
            // class name. Deliberately not illustrated with a concrete host FQCN
            // here — this package ships to apps whose models are named otherwise.
            $table->string('batchable_type');
            $table->integer('total_items');
            $table->integer('processed_items')->default(0);
            $table->integer('failed_items')->default(0);
            $table->json('item_ids');
            $table->json('failed_item_ids')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamps();

            $table->index('batchable_type');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_content_batches');
    }
};
