<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liberação Operacional SEM dependência financeira + Ownership por item.
 *
 * - Remove itens financeiros (pagamento_validado/financeiro_aprovado): o Minutor NÃO é fonte de verdade
 *   financeira. A liberação responde apenas "a operação tem condições de iniciar a execução?".
 * - Renomeia contrato_assinado → cobertura_juridica_confirmada (auto quando há Contrato Guarda-Chuva).
 * - Adiciona owner_role + sla_days (área responsável + prazo) em templates e itens.
 * - Re-semeia os templates por tipo (Implantação/Projeto Fechado, Banco de Horas, Sustentação, Recorrência).
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['contract_release_checklist_templates', 'contract_release_checklist_items'] as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (!Schema::hasColumn($t, 'owner_role')) $table->string('owner_role', 24)->nullable();
                if (!Schema::hasColumn($t, 'sla_days'))   $table->unsignedSmallInteger('sla_days')->nullable();
            });
        }

        // Limpa itens antigos (Replica/teste) — re-instanciam com o novo modelo na próxima abertura.
        DB::table('contract_release_checklist_items')->delete();
        // Re-semeia os templates do zero.
        DB::table('contract_release_checklist_templates')->delete();

        // Catálogo de itens operacionais (owner_role + sla_days).
        $cat = [
            'cobertura_juridica_confirmada' => ['Cobertura jurídica confirmada', 'comercial', 1],
            'cadastro_validado'             => ['Cadastro validado', 'administrativo', 1],
            'documentacao_recebida'         => ['Documentação recebida', 'administrativo', 3],
            'kickoff_agendado'              => ['Kickoff agendado', 'operacoes', 5],
        ];
        // Conjuntos por escopo (todos obrigatórios).
        $sets = [
            ['default', null,                  ['cobertura_juridica_confirmada', 'cadastro_validado']],
            ['tipo_faturamento', 'por_servico', ['cobertura_juridica_confirmada', 'cadastro_validado', 'documentacao_recebida', 'kickoff_agendado']], // Implantação / Projeto Fechado
            ['tipo_faturamento', 'banco_horas_fixo',  ['cobertura_juridica_confirmada', 'cadastro_validado']], // Banco de Horas
            ['tipo_faturamento', 'banco_horas_mensal', ['cobertura_juridica_confirmada', 'cadastro_validado']], // Recorrência
            ['tipo_faturamento', 'on_demand',   ['cobertura_juridica_confirmada', 'cadastro_validado']], // Recorrência sob demanda
            ['tipo_faturamento', 'saas',        ['cobertura_juridica_confirmada']], // Sustentação
        ];
        $now = now();
        $rows = [];
        foreach ($sets as [$scopeType, $scopeValue, $keys]) {
            $ordem = 1;
            foreach ($keys as $key) {
                [$label, $role, $sla] = $cat[$key];
                $rows[] = [
                    'scope_type' => $scopeType, 'scope_value' => $scopeValue, 'item_key' => $key, 'label' => $label,
                    'obrigatorio' => true, 'ordem' => $ordem++, 'ativo' => true,
                    'owner_role' => $role, 'sla_days' => $sla, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        DB::table('contract_release_checklist_templates')->insert($rows);
    }

    public function down(): void
    {
        foreach (['contract_release_checklist_templates', 'contract_release_checklist_items'] as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                foreach (['owner_role', 'sla_days'] as $c) {
                    if (Schema::hasColumn($t, $c)) $table->dropColumn($c);
                }
            });
        }
    }
};
