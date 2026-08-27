<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('image_director_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('applicable_model')->nullable();
            $table->text('description')->nullable();
            $table->text('system_prompt')->nullable();
            $table->text('style_guidance')->nullable();
            $table->json('forbidden')->nullable();
            $table->json('always_include')->nullable();
            $table->text('negative_prompt')->nullable();
            $table->foreignId('default_image_style_id')
                ->nullable()
                ->constrained('image_styles')
                ->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $styleId = DB::table('image_styles')->where('slug', 'photorealistic')->value('id');
        $now = now();

        DB::table('image_director_profiles')->insert([
            'name' => 'General (default)',
            'slug' => 'general-default',
            'applicable_model' => null,
            'description' => 'Default brand guidance for all AI-generated images.',
            'system_prompt' => null,
            'style_guidance' => 'Clean, modern, trustworthy medical and editorial imagery. Bright, hygienic, well-lit settings with a calm, professional mood. Neutral palette with soft blue/teal clinical accents. Realistic, accurate medical equipment. Shallow depth of field. Reassuring, never alarming.',
            'forbidden' => json_encode([
                'on-image text or captions',
                'logos and brand marks',
                'watermarks',
                'graphic wounds or blood',
                'surgery gore',
                'identifiable real patient faces',
                'distressing or alarming scenes',
                'inaccurate or fake-looking medical devices',
                'competitor branding',
            ], JSON_THROW_ON_ERROR),
            'always_include' => json_encode([
                'clean professional healthcare setting',
                'modern accurate medical equipment',
                'good hygiene cues',
                'calm reassuring tone',
                'soft natural lighting',
            ], JSON_THROW_ON_ERROR),
            'negative_prompt' => 'text, watermark, logo, signature, distorted anatomy, extra limbs, blurry, low quality, deformed hands, cartoonish gore',
            'default_image_style_id' => $styleId,
            'is_default' => true,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_director_profiles');
    }
};
