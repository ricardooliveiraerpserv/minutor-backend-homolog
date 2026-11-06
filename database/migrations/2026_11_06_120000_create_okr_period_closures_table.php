<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fechamento mensal por área (okr_area_id null = fechamento global/consolidado da
 * diretoria). Guarda quem/quando fechou E quem/quando/por que reabriu — melhoria
 * sobre project_open_periods, que perde o histórico ao reabrir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('okr_period_closures')) {
            Schema::create('okr_period_closures', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->foreignId('okr_cycle_id')->constrained('okr_cycles')->cascadeOnDelete();
                $t->foreignId('okr_area_id')->nullable()->constrained('okr_areas')->nullOnDelete();
                $t->string('year_month', 7);
                // aberto | em_revisao | fechado
                $t->string('status', 15)->default('aberto');
                $t->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('closed_at')->nullable();
                $t->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('reopened_at')->nullable();
                $t->text('reopen_reason')->nullable();
                $t->timestamps();

                $t->unique(['okr_cycle_id', 'okr_area_id', 'year_month']);
                $t->index(['okr_cycle_id', 'year_month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_period_closures');
    }
};
