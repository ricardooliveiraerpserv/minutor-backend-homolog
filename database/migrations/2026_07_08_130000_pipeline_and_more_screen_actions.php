<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registra no catálogo (screen_actions) as ações REAIS de telas já instrumentadas, que
 * faltavam — sem elas o admin não consegue liberar/bloquear a ação no Configurador.
 * - /contratos/pipeline: Alterar Status, Custo, Apont. & Despesas, Selecionar Equipe, Excluir.
 * - /approvals: Reprovar (o FE checa 'reject').
 * - /expenses: Pagar, Reabrir (o FE checa 'pay'/'reopen').
 * Idempotente (updateOrInsert).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('screen_actions')) return;

        // label => [label, description]
        $L = [
            'status'     => ['Alterar Status',    'Mudar o status do projeto'],
            'cost'       => ['Custo',             'Ver custo / margem do projeto'],
            'timesheets' => ['Apont. & Despesas', 'Ver apontamentos e despesas'],
            'team'       => ['Selecionar Equipe', 'Definir consultores/coordenadores'],
            'delete'     => ['Excluir',           'Excluir o registro'],
            'reject'     => ['Reprovar',          'Reprovar o apontamento/despesa'],
            'pay'        => ['Pagar',             'Marcar como pago'],
            'reopen'     => ['Reabrir',           'Reabrir / estornar'],
        ];

        // screen_key => [action_keys a garantir] — mantém sort_order alto (após os default).
        $add = [
            '/contratos/pipeline' => ['status', 'cost', 'timesheets', 'team', 'delete'],
            '/approvals'          => ['reject'],
            '/expenses'           => ['pay', 'reopen'],
        ];

        $now = now();
        foreach ($add as $screen => $actions) {
            $base = (int) (DB::table('screen_actions')->where('screen_key', $screen)->max('sort_order') ?? -1) + 1;
            foreach ($actions as $i => $a) {
                if (!isset($L[$a])) continue;
                DB::table('screen_actions')->updateOrInsert(
                    ['screen_key' => $screen, 'action_key' => $a],
                    ['label' => $L[$a][0], 'description' => $L[$a][1], 'sort_order' => $base + $i, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('screen_actions')->where('screen_key', '/contratos/pipeline')->whereIn('action_key', ['status', 'cost', 'timesheets', 'team', 'delete'])->delete();
        DB::table('screen_actions')->where('screen_key', '/approvals')->where('action_key', 'reject')->delete();
        DB::table('screen_actions')->where('screen_key', '/expenses')->whereIn('action_key', ['pay', 'reopen'])->delete();
    }
};
