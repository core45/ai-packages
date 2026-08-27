<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_detections', function (Blueprint $table) {
            $table->id();
            $table->morphs('detectable');
            $table->string('locale', 10);
            $table->float('score')->nullable();
            $table->json('result_details')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('external_id')->nullable();
            $table->timestamps();

            $table->index(['detectable_type', 'detectable_id', 'locale', 'created_at'], 'ai_detections_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_detections');
    }
};
