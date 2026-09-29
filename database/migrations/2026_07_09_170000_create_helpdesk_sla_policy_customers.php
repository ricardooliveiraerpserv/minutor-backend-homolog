<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo N:N entre política de SLA e CLIENTES. Um SLA cadastrado pode valer para vários
 * clientes (ex.: contrato Cosan = 52 clientes). Cliente vinculado usa esse SLA; ao ser
 * desvinculado, volta a usar o SLA padrão. (Substitui o customer_id único como forma
 * principal de vínculo; o customer_id legado segue sendo respeitado como fallback.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_sla_policy_customers')) {
            Schema::create('helpdesk_sla_policy_customers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sla_policy_id')->constrained('helpdesk_sla_policies')->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['sla_policy_id', 'customer_id']);
                // Um cliente só pode estar vinculado a UMA política (o SLA que ele usa).
                $table->unique('customer_id', 'helpdesk_sla_policy_customers_customer_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_sla_policy_customers');
    }
};
