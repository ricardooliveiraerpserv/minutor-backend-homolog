<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de pagamento de comissão. Uma linha por oportunidade GANHA (apuração),
 * com máquina de status: apurada → aprovada → paga; e bloqueada/cancelada.
 * A base/percentual são congelados no momento da apuração (auditoria).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_commissions')) return;
        Schema::create('crm_commissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('opportunity_id')->unique();
            $t->unsignedBigInteger('user_id')->index();       // responsável comissionado
            $t->char('competencia', 7)->index();               // YYYY-MM (mês do ganho)
            $t->decimal('base', 15, 2)->default(0);            // valor da venda (congelado)
            $t->decimal('percentual', 5, 2)->default(0);       // % no momento da apuração
            $t->decimal('valor', 15, 2)->default(0);           // comissão = base * %
            $t->string('status', 20)->default('apurada');      // apurada|aprovada|paga|bloqueada|cancelada
            $t->unsignedBigInteger('approved_by_id')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('motivo', 200)->nullable();             // bloqueio/cancelamento
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_commissions');
    }
};
