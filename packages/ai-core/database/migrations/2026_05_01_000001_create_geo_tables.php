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
     * THE FILENAME IS PLURAL AND THIS CREATES ONE TABLE. That is deliberate and
     * renaming the file would break it.
     *
     * Laravel keys the `migrations` ledger on basename, so a package migration
     * has to carry the basename the consuming installs already recorded — or it
     * has no ledger row there, runs, and hits "table already exists" on a table
     * they already have. The hosts' row is
     * `2026_05_01_000001_create_geo_tables`, and their file creates both
     * `geo_profiles` and `geo_score_snapshots`.
     *
     * Only `geo_profiles` belongs to this package. `geo_score_snapshots` is
     * host-owned (`App\Models\GeoScoreSnapshot`) and no packaged model reads it.
     * So the file keeps the hosts' basename, which is what makes the ledger line
     * up, and creates the one table that is genuinely its own.
     *
     * A fresh install therefore gets `geo_profiles` and no snapshots table,
     * which is correct: nothing in these packages needs one.
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
