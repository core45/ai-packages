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
        Schema::create('ai_analytics_insights', function (Blueprint $table) {
            $table->id();
            $table->enum('tab_type', ['revenue', 'customers', 'orders', 'subscriptions', 'ai_insights']);
            $table->date('start_date');
            $table->date('end_date');
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->string('locale', 5)->default('en');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tab_type');
            $table->index(['start_date', 'end_date']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_analytics_insights');
    }
};
