<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro F3 — documentos fiscais emitidos a partir das parcelas: NFS-e (API nacional)
 * e Nota de Débito (documento próprio, numeração por empresa). Uma nota "viva" por parcela.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_invoices')) {
            Schema::create('fin_invoices', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->foreignId('installment_id')->constrained('fin_installments')->restrictOnDelete();
                $t->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
                $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $t->string('tipo', 20);                       // nfse | nota_debito
                $t->string('emissor', 20);                    // nacional | simulado | proprio (ND)
                $t->string('ambiente', 20)->nullable();       // homologacao | producao (NFS-e)
                $t->string('status', 20)->default('enviando'); // enviando|autorizada|rejeitada|erro|cancelada
                $t->string('serie', 5)->nullable();
                $t->unsignedBigInteger('numero_dps')->nullable();
                $t->string('numero', 30)->nullable();          // nº NFS-e ou nº da ND
                $t->string('chave_acesso', 60)->nullable();
                $t->string('codigo_verificacao', 60)->nullable();
                $t->string('competencia', 7);
                $t->date('data_emissao')->nullable();
                $t->decimal('valor_servico', 14, 2);
                $t->decimal('desconto', 14, 2)->default(0);
                $t->decimal('valor_iss', 14, 2)->default(0);
                $t->decimal('total_retencoes', 14, 2)->default(0);
                $t->decimal('valor_liquido', 14, 2);
                $t->text('discriminacao')->nullable();
                $t->json('tomador')->nullable();               // snapshot do cliente na emissão
                $t->json('mensagens')->nullable();             // retorno/erros da API
                $t->longText('xml_dps')->nullable();
                $t->longText('xml_nfse')->nullable();
                $t->timestamp('emitida_em')->nullable();
                $t->timestamp('cancelada_em')->nullable();
                $t->text('motivo_cancelamento')->nullable();
                $t->foreignId('emitida_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['company_id', 'competencia', 'status']);
                $t->index(['company_id', 'tipo', 'numero']);
                $t->unique(['company_id', 'tipo', 'emissor', 'serie', 'numero_dps'], 'fin_invoices_dps_unica');
            });
        }

        Schema::table('fin_company_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('fin_company_settings', 'nd_proximo_numero')) {
                $t->unsignedBigInteger('nd_proximo_numero')->default(1);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_invoices');
        Schema::table('fin_company_settings', function (Blueprint $t) {
            if (Schema::hasColumn('fin_company_settings', 'nd_proximo_numero')) $t->dropColumn('nd_proximo_numero');
        });
    }
};
