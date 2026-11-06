<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metas mensais de um KR. year_month no padrão do projeto ('2027-01').
 * target_value NULLABLE: null = KR não ativo naquele mês (ex.: KR iniciado no
 * meio do ano). Separa META de REALIZADO (okr_monthly_results, Fase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('okr_monthly_targets')) {
            Schema::create('okr_monthly_targets', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->foreignId('okr_key_result_id')->constrained('okr_key_results')->cascadeOnDelete();
                $t->string('year_month', 7)->comment("Competência no formato '2027-01'");
                $t->decimal('target_value', 16, 4)->nullable();
                $t->timestamps();

                $t->unique(['okr_key_result_id', 'year_month']);
                $t->index('okr_key_result_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_monthly_targets');
    }
};
