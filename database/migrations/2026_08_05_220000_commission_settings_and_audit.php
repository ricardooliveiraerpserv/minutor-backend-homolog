<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Governança de Comissões: separa configuração da operação.
 * - crm_commission_settings: Política Padrão da empresa (% padrão, base de cálculo,
 *   momento do pagamento, forma de cálculo).
 * - crm_commission_rate_history: auditoria das exceções por vendedor (quem, quando,
 *   valor anterior/novo, motivo, IP).
 * - vigência (início/fim) nas exceções (crm_commission_rates).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_commission_settings')) {
            Schema::create('crm_commission_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable()->unique();
                $t->decimal('percentual_padrao', 5, 2)->default(0);
                $t->string('base_calculo', 20)->default('valor');   // valor | receita_liquida | margem
                $t->string('pagamento', 20)->default('ganho');       // ganho | faturado | recebido
                $t->string('forma_calculo', 20)->default('fixo');    // fixo | progressivo | faixa | margem
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('crm_commission_rate_history')) {
            Schema::create('crm_commission_rate_history', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index(); // null = política padrão
                $t->decimal('valor_anterior', 5, 2)->nullable();
                $t->decimal('valor_novo', 5, 2)->nullable();
                $t->string('campo', 30)->default('percentual'); // percentual | politica_padrao
                $t->string('motivo', 200)->nullable();
                $t->unsignedBigInteger('changed_by_id')->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
        Schema::table('crm_commission_rates', function (Blueprint $t) {
            if (!Schema::hasColumn('crm_commission_rates', 'vigencia_inicio')) $t->date('vigencia_inicio')->nullable();
            if (!Schema::hasColumn('crm_commission_rates', 'vigencia_fim')) $t->date('vigencia_fim')->nullable();
            if (!Schema::hasColumn('crm_commission_rates', 'motivo')) $t->string('motivo', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_commission_settings');
        Schema::dropIfExists('crm_commission_rate_history');
        Schema::table('crm_commission_rates', function (Blueprint $t) {
            $t->dropColumn(['vigencia_inicio', 'vigencia_fim', 'motivo']);
        });
    }
};
