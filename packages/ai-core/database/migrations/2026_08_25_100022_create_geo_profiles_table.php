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
     * Only `geo_profiles` is created here; `geo_score_snapshots` from the
     * reference implementation is omitted since no model in this application
     * uses it yet.
     */
    public function up(): void
    {
        Schema::create('geo_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('locale', 10);
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('grade', 2)->default('F');
            $table->json('audit')->nullable();
            $table->timestamp('citations_enriched_at')->nullable();
            $table->timestamps();

            $table->unique(['model_type', 'model_id', 'locale']);
            $table->index(['model_type', 'score']);
            $table->index('grade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geo_profiles');
    }
};
