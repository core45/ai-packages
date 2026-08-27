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
        Schema::table('ai_detections', function (Blueprint $table) {
            $table->string('operation_id', 36)->nullable()->unique('ai_detections_operation_id_unique');
            $table->string('client_reference')->nullable();
            $table->string('phasea_status', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('poll_give_up_at')->nullable();
        });

        Schema::table('ai_humanizations', function (Blueprint $table) {
            $table->string('operation_id', 36)->nullable()->unique('ai_humanizations_operation_id_unique');
            $table->string('client_reference')->nullable();
            $table->string('phasea_status', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('poll_give_up_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_detections', function (Blueprint $table) {
            $table->dropUnique('ai_detections_operation_id_unique');
            $table->dropColumn([
                'operation_id',
                'client_reference',
                'phasea_status',
                'error_code',
                'poll_give_up_at',
            ]);
        });

        Schema::table('ai_humanizations', function (Blueprint $table) {
            $table->dropUnique('ai_humanizations_operation_id_unique');
            $table->dropColumn([
                'operation_id',
                'client_reference',
                'phasea_status',
                'error_code',
                'poll_give_up_at',
            ]);
        });
    }
};
