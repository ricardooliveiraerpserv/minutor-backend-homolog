<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4.1 — Checklist de Liberação CONFIGURÁVEL (não fixo no código).
 *
 * templates: itens exigidos por escopo (default | categoria | tipo_faturamento).
 * items: instância por contrato (snapshot do template no momento da emissão).
 * A liberação operacional só é permitida com os itens OBRIGATÓRIOS+APLICÁVEIS marcados.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('contract_release_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 24)->default('default'); // default|categoria|tipo_faturamento|contract_type
            $table->string('scope_value', 48)->nullable();        // ex.: 'projeto', 'banco_horas_fixo', null p/ default
            $table->string('item_key', 48);
            $table->string('label');
            $table->boolean('obrigatorio')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['scope_type', 'scope_value', 'item_key'], 'crc_tpl_unique');
        });

        Schema::create('contract_release_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('item_key', 48);
            $table->string('label');
            $table->boolean('obrigatorio')->default(true);
            $table->boolean('aplicavel')->default(true);
            $table->boolean('checked')->default(false);
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
            $table->unique(['contract_id', 'item_key'], 'crc_item_unique');
        });

        // Seed inicial (configurável depois pela administração).
        $now = now();
        $rows = [];
        $add = function (string $st, ?string $sv, string $key, string $label, bool $obr, int $ord) use (&$rows, $now) {
            $rows[] = ['scope_type' => $st, 'scope_value' => $sv, 'item_key' => $key, 'label' => $label, 'obrigatorio' => $obr, 'ordem' => $ord, 'ativo' => true, 'created_at' => $now, 'updated_at' => $now];
        };
        // DEFAULT (vale p/ qualquer contrato sem template específico)
        $add('default', null, 'contrato_assinado', 'Contrato assinado', true, 1);
        $add('default', null, 'financeiro_aprovado', 'Financeiro aprovado', true, 2);
        $add('default', null, 'cadastro_validado', 'Cadastro validado', false, 3);
        $add('default', null, 'kickoff_agendado', 'Kickoff agendado', false, 4);
        $add('default', null, 'documentacao_recebida', 'Documentação recebida', false, 5);
        $add('default', null, 'pagamento_validado', 'Pagamento validado', false, 6);
        // Projeto Fechado (categoria projeto) — exige kickoff
        $add('tipo_faturamento', 'por_servico', 'contrato_assinado', 'Contrato assinado', true, 1);
        $add('tipo_faturamento', 'por_servico', 'kickoff_agendado', 'Kickoff agendado', true, 2);
        $add('tipo_faturamento', 'por_servico', 'financeiro_aprovado', 'Financeiro aprovado', true, 3);
        // Banco de Horas — exige pagamento
        $add('tipo_faturamento', 'banco_horas_fixo', 'contrato_assinado', 'Contrato assinado', true, 1);
        $add('tipo_faturamento', 'banco_horas_fixo', 'pagamento_validado', 'Pagamento validado', true, 2);
        $add('tipo_faturamento', 'banco_horas_mensal', 'contrato_assinado', 'Contrato assinado', true, 1);
        $add('tipo_faturamento', 'banco_horas_mensal', 'pagamento_validado', 'Pagamento validado', true, 2);
        DB::table('contract_release_checklist_templates')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_release_checklist_items');
        Schema::dropIfExists('contract_release_checklist_templates');
    }
};
