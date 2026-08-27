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
     * Consolidates the reference implementation's two migrations
     * (create_seo_indexables_table + add_social_overrides_to_seo_indexables_table)
     * into a single create, dropping every `->after()` call for SQLite.
     */
    public function up(): void
    {
        Schema::create('seo_indexables', function (Blueprint $table): void {
            $table->id();
            $table->string('indexable_type', 100);
            $table->unsignedBigInteger('indexable_id');
            $table->string('locale', 10);

            $table->string('title', 512)->nullable();
            $table->text('description')->nullable();
            $table->string('canonical', 2048)->nullable();
            $table->string('robots', 100)->default('index, follow');
            $table->boolean('is_noindex')->default(false);

            $table->json('og_data')->nullable();
            $table->json('twitter_data')->nullable();

            $table->json('schema_json')->nullable();

            $table->string('og_title', 255)->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image', 2048)->nullable();
            $table->string('twitter_title', 255)->nullable();
            $table->text('twitter_description')->nullable();
            $table->string('twitter_image', 2048)->nullable();

            $table->unsignedTinyInteger('seo_score')->nullable();
            $table->unsignedTinyInteger('readability_score')->nullable();
            $table->string('overall_status', 20)->nullable();
            $table->json('analysis_issues')->nullable();

            $table->json('keyphrases')->nullable();

            $table->unsignedInteger('internal_links_inbound')->default(0);
            $table->unsignedInteger('internal_links_outbound')->default(0);
            $table->timestamp('links_counted_at')->nullable();

            $table->boolean('is_cornerstone')->default(false);

            $table->timestamp('last_modified')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->unique(['indexable_type', 'indexable_id', 'locale'], 'seo_indexables_unique');
            $table->index(['is_noindex', 'indexable_type']);
            $table->index(['is_cornerstone']);
            $table->index(['seo_score']);
            $table->index(['overall_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seo_indexables');
    }
};
