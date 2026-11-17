<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro F2 — regras de faturamento por contrato + parcelas (o que vai virar NFS-e /
 * nota de débito e título a receber nas fases seguintes). Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_contract_billings')) {
            Schema::create('fin_contract_billings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('contract_id')->unique()->constrained('contracts')->cascadeOnDelete();
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->boolean('ativo')->default(true);
                // Recorrência: none | mensalidade (valor do projeto vigente) | banco_horas (horas vendidas × valor-hora) | valor_fixo
                $t->string('regra_recorrente', 20)->default('none');
                $t->decimal('valor_fixo', 14, 2)->nullable();
                $t->string('periodicidade', 12)->default('mensal'); // mensal|bimestral|trimestral|semestral|anual
                $t->string('inicio_competencia', 7)->nullable();    // YYYY-MM
                $t->string('fim_competencia', 7)->nullable();
                $t->string('documento', 20)->default('nfse');       // nfse|nota_debito|nenhum (serviços)
                $t->string('codigo_tributacao_nacional', 20)->nullable();
                $t->text('discriminacao')->nullable();              // modelo com {competencia} {contrato} {horas} {valor_liquido}
                $t->unsignedTinyInteger('dia_faturamento')->default(1);
                $t->unsignedSmallInteger('vencimento_dias')->default(10);
                $t->foreignId('bank_account_id')->nullable()->constrained('fin_bank_accounts')->nullOnDelete();
                $t->foreignId('chart_account_id')->nullable()->constrained('fin_chart_accounts')->nullOnDelete();
                $t->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                // Alíquotas: null = usa as da empresa (fin_company_settings)
                $t->boolean('reter_federais')->default(true);
                $t->decimal('aliquota_irrf', 6, 4)->nullable();
                $t->decimal('aliquota_csll', 6, 4)->nullable();
                $t->decimal('aliquota_pis', 6, 4)->nullable();
                $t->decimal('aliquota_cofins', 6, 4)->nullable();
                $t->decimal('aliquota_iss', 6, 4)->nullable();
                $t->boolean('iss_retido')->default(false);
                $t->text('observacoes')->nullable();
                $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('fin_installments')) {
            Schema::create('fin_installments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
                $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $t->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
                $t->string('competencia', 7);                // YYYY-MM
                $t->string('origem', 20);                    // recorrente|on_demand|excedente|despesa|avulsa
                $t->string('origem_ref', 80);                // chave de idempotência dentro de contrato+competência+origem
                $t->string('documento', 20);                 // nfse|nota_debito|nenhum
                $t->string('descricao')->nullable();
                $t->decimal('horas', 10, 2)->nullable();
                $t->decimal('valor_bruto', 14, 2);
                $t->decimal('desconto', 14, 2)->default(0);
                $t->decimal('ret_irrf', 14, 2)->default(0);
                $t->decimal('ret_csll', 14, 2)->default(0);
                $t->decimal('ret_pis', 14, 2)->default(0);
                $t->decimal('ret_cofins', 14, 2)->default(0);
                $t->decimal('ret_iss', 14, 2)->default(0);
                $t->decimal('valor_iss', 14, 2)->default(0); // ISS devido (informativo quando não retido)
                $t->decimal('valor_liquido', 14, 2);
                $t->date('vencimento')->nullable();
                $t->string('status', 20)->default('prevista'); // prevista|pronta|processada|cancelada
                $t->json('detalhes')->nullable();            // origem: ids do fechamento/excedente/despesas, cálculo
                $t->text('motivo_cancelamento')->nullable();
                $t->timestamp('processada_em')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['contract_id', 'competencia', 'origem', 'origem_ref'], 'fin_installments_idem');
                $t->index(['company_id', 'competencia', 'status']);
                $t->index(['customer_id', 'competencia']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_installments');
        Schema::dropIfExists('fin_contract_billings');
    }
};
