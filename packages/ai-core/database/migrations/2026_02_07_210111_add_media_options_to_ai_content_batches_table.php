<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_content_batches', function (Blueprint $table) {
            $table->json('media_options')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_content_batches', function (Blueprint $table) {
            $table->dropColumn('media_options');
        });
    }
};
