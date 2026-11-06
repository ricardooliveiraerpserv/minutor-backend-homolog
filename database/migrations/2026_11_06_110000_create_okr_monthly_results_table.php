<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Realização mensal + check-in gerencial de um KR (competência year_month).
 * actual_value NULLABLE é intencional: null = NÃO INFORMADO (pendente), que é
 * diferente de 0 (zero realizado). Nunca tratar pendente como zero nos cálculos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('okr_monthly_results')) {
            Schema::create('okr_monthly_results', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->foreignId('okr_key_result_id')->constrained('okr_key_results')->cascadeOnDelete();
                $t->string('year_month', 7);
                $t->decimal('actual_value', 16, 4)->nullable()->comment('null = não informado (pendente) ≠ 0');
                // Check-in gerencial
                $t->text('comment')->nullable();
                $t->text('risks')->nullable();
                $t->text('action_plan')->nullable();
                $t->string('situation', 30)->nullable()->comment('Situação informada pelo responsável');
                $t->string('source', 10)->default('manual'); // manual | automatico
                $t->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('reported_at')->nullable();
                $t->timestamps();

                $t->unique(['okr_key_result_id', 'year_month']);
                $t->index('okr_key_result_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_monthly_results');
    }
};
