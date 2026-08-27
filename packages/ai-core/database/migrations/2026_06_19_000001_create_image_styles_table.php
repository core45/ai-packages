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
        Schema::create('image_styles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('prompt_fragment');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $styles = [
            ['slug' => 'photorealistic', 'name' => 'Photorealistic (editorial)', 'prompt_fragment' => 'clean, modern editorial photography, natural lighting, realistic'],
            ['slug' => 'studio_product', 'name' => 'Studio product photography', 'prompt_fragment' => 'studio lighting, seamless background, sharp commercial product shot'],
            ['slug' => 'clinical', 'name' => 'Clinical / medical', 'prompt_fragment' => 'bright hygienic clinical setting, professional healthcare photography, trustworthy and calm'],
            ['slug' => 'lifestyle', 'name' => 'Lifestyle / documentary', 'prompt_fragment' => 'candid real-world scene, natural light, authentic documentary feel'],
            ['slug' => 'cinematic', 'name' => 'Cinematic', 'prompt_fragment' => 'dramatic lighting, shallow depth of field, filmic color grade'],
            ['slug' => 'macro', 'name' => 'Macro detail', 'prompt_fragment' => 'extreme close-up, fine texture and detail, crisp focus'],
            ['slug' => 'vintage_film', 'name' => 'Vintage film', 'prompt_fragment' => 'analog film grain, muted tones, retro photographic look'],
            ['slug' => 'flat_vector', 'name' => 'Flat vector illustration', 'prompt_fragment' => 'clean flat vector art, simple shapes, limited color palette'],
            ['slug' => 'line_art', 'name' => 'Line art / technical', 'prompt_fragment' => 'precise line-art technical illustration, schematic clarity'],
            ['slug' => 'watercolor', 'name' => 'Watercolor', 'prompt_fragment' => 'soft watercolor painting, gentle washes, organic edges'],
            ['slug' => 'isometric', 'name' => 'Isometric', 'prompt_fragment' => 'isometric illustration, 3/4 perspective, tidy geometric composition'],
            ['slug' => 'cartoon', 'name' => 'Cartoon / comic', 'prompt_fragment' => 'bold cartoon style, clean outlines, vibrant flat colors'],
            ['slug' => 'friendly_storybook', 'name' => 'Friendly storybook', 'prompt_fragment' => "warm children's-book illustration, soft and approachable"],
            ['slug' => 'infographic', 'name' => 'Infographic style', 'prompt_fragment' => 'clean infographic visual, iconographic and explanatory (no readable text)'],
            ['slug' => 'render_3d', 'name' => '3D product render', 'prompt_fragment' => 'high-quality 3D render, soft studio lighting, photoreal materials'],
            ['slug' => 'clay', 'name' => 'Claymation / clay', 'prompt_fragment' => 'soft clay-model render, rounded forms, tactile matte surfaces'],
            ['slug' => 'low_poly', 'name' => 'Low-poly', 'prompt_fragment' => 'stylized low-poly 3D, faceted geometry, clean gradients'],
            ['slug' => 'minimalist', 'name' => 'Minimalist', 'prompt_fragment' => 'minimalist composition, generous negative space, one clear subject'],
            ['slug' => 'blueprint', 'name' => 'Blueprint / schematic', 'prompt_fragment' => 'technical blueprint look, white linework on blue, annotated feel'],
        ];

        $now = now();

        foreach ($styles as $index => $style) {
            DB::table('image_styles')->insert([
                'slug' => $style['slug'],
                'name' => $style['name'],
                'prompt_fragment' => $style['prompt_fragment'],
                'sort_order' => $index,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_styles');
    }
};
