<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Multiplicador de horas faturáveis ao cliente, por CONTRATO.
 *
 * Regra: nas horas apontadas do contrato, aplica um uplift percentual APENAS no lado
 * do cliente (fechamentos cliente/contrato/admin/diretoria/excedente + RECEITA da
 * rentabilidade). NUNCA afeta o lado operacional (consultor/parceiro): apontamento,
 * fechamento, banco de horas, pagamento e o CUSTO na rentabilidade seguem as horas
 * REAIS. As horas a mais têm custo ZERO. O cliente vê o total já multiplicado, sem
 * enxergar o fator.
 *
 * `percent` = uplift: 50 → ×1,5 (2h→3h), 100 → ×2 (2h→4h). Fator = 1 + percent/100.
 * Vigência: [start_date, end_date]; end_date NULL = sem fim ("full").
 * Trava: no máximo UMA regra ativa por contrato (índice único parcial).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_hour_multipliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->decimal('percent', 8, 2); // uplift %: 50 = +50% (×1,5)
            $table->date('start_date');
            $table->date('end_date')->nullable(); // NULL = sem fim ("full")
            $table->boolean('active')->default(true);
            $table->text('reason')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['contract_id', 'active']);
            $table->index('customer_id');
        });

        // Trava dura: no máximo 1 regra ATIVA por contrato (ignora as inativas/apagadas).
        DB::statement(
            'CREATE UNIQUE INDEX contract_hour_multipliers_one_active_per_contract '
            . 'ON contract_hour_multipliers (contract_id) '
            . 'WHERE active AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_hour_multipliers');
    }
};
