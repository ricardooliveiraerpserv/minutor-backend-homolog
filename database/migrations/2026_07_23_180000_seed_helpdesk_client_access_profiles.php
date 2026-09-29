<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cria 3 perfis de acesso de CLIENTE prontos, variando só o escopo de visão de chamados
 * (tickets.view_tickets). Idempotente (updateOrInsert por nome+kind). Não marca is_default
 * (já existe um padrão de cliente). Os demais toggles espelham os defaults do formulário.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defaults do perfil CLIENTE (espelham defaultsFor('cliente') do formulário de Perfis).
        $base = [
            'tickets.open'                => 'same_org',
            'tickets.inform.service'      => false,
            'tickets.inform.category'     => true,
            'tickets.inform.urgency'      => false,
            'tickets.inform.subject'      => true,
            'tickets.inform.tags'         => false,
            'tickets.kb_suggestions'      => false,
            'tickets.open_on_behalf'      => false,
            'tickets.personal_views'      => true,
            'tickets.view_in.service'     => true,
            'tickets.view_in.responsible' => true,
            'tickets.view_in.category'    => true,
            'tickets.view_in.urgency'     => false,
            'tickets.view_in.sla_due'     => true,
            'tickets.view_in.sla_first'   => false,
            'tickets.view_in.status'      => true,
            'tickets.view_in.justification' => true,
            'tickets.view_in.subject'     => true,
            'tickets.view_in.agent_times' => false,
            'tickets.view_in.tags'        => false,
            'general.edit_own_profile'    => false,
            'general.default_screen'      => 'principal',
            'policies.all_catalog'        => true,
            'time.see_worked_values'      => false,
            'time.contract_summary'       => false,
            'time.contract_chart'         => false,
            'time.see_extra_expenses'     => false,
        ];

        $profiles = [
            ['Cliente — Organização (todos da empresa)', 'same_org',   10],
            ['Cliente — Departamento',                   'department', 11],
            ['Cliente — Somente próprios',               'own',        12],
        ];

        foreach ($profiles as [$name, $scope, $order]) {
            DB::table('helpdesk_access_profiles')->updateOrInsert(
                ['name' => $name, 'kind' => 'cliente', 'deleted_at' => null],
                [
                    'enabled'     => true,
                    'is_default'  => false,
                    'permissions' => json_encode($base + ['tickets.view_tickets' => $scope]),
                    'sort_order'  => $order,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('helpdesk_access_profiles')
            ->where('kind', 'cliente')
            ->whereIn('name', [
                'Cliente — Organização (todos da empresa)',
                'Cliente — Departamento',
                'Cliente — Somente próprios',
            ])->delete();
    }
};
