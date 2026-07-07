<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\NavModule;
use App\Models\NavScreen;

/**
 * Módulos "consultor" e "parceiro" no Configurador (abas separadas), espelhando os menus
 * próprios desses perfis. Não entram no launcher interno. Telas ganham o perfil correspondente.
 * Observação: o gating fino do consultor (itens por permissão) segue no código da sidebar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ensure = function (string $key, string $label, string $profile) {
            $s = NavScreen::firstOrNew(['key' => $key]);
            $profiles = $s->exists ? ($s->profiles ?? []) : [];
            if (!in_array($profile, $profiles, true)) $profiles[] = $profile;
            $s->profiles = array_values($profiles);
            $s->route = $s->route ?: $key;
            if (!$s->exists) $s->active = true;
            if (empty($s->label)) $s->label = $label;
            $s->users = $s->users ?? [];
            $s->save();
        };

        // ── Consultor ──
        foreach ([
            '/inicio' => 'Meu Dia', '/meu-painel' => 'Meu Painel', '/timesheets' => 'Apontamentos',
            '/approvals' => 'Aprovações', '/settings' => 'Configurações',
            '/help-desk/tickets' => 'Chamados', '/help-desk/fila' => 'Fila (Kanban)', '/help-desk/kb' => 'Base de Conhecimento',
        ] as $k => $l) $ensure($k, $l, 'consultor');

        $consultorTree = [
            ['id' => 'n_consultor_0', 'screen' => '/inicio'],
            ['id' => 'n_consultor_1', 'screen' => '/meu-painel'],
            ['id' => 'n_consultor_2', 'screen' => '/timesheets'],
            ['id' => 'n_consultor_3', 'screen' => '/approvals'],
            ['id' => 'n_consultor_4', 'screen' => '/settings'],
            ['id' => 'g_consultor_5', 'label' => 'Help Desk', 'icon' => 'Headphones', 'children' => [
                ['id' => 'n_consultor_6', 'screen' => '/help-desk/tickets'],
                ['id' => 'n_consultor_7', 'screen' => '/help-desk/fila'],
                ['id' => 'n_consultor_8', 'screen' => '/help-desk/kb'],
            ]],
        ];

        // ── Parceiro ──
        foreach (['/partner-dashboard' => 'Painel do Parceiro', '/meu-painel' => 'Meu Painel'] as $k => $l) $ensure($k, $l, 'parceiro_admin');
        $parceiroTree = [
            ['id' => 'n_parceiro_0', 'screen' => '/partner-dashboard'],
            ['id' => 'n_parceiro_1', 'screen' => '/meu-painel'],
        ];

        $base = (int) NavModule::max('sort_order');
        NavModule::updateOrCreate(['key' => 'consultor'], [
            'label' => 'Consultor', 'icon' => 'UserCheck', 'sort_order' => $base + 1,
            'is_system' => true, 'active' => true, 'profiles' => ['consultor'], 'items' => $consultorTree,
        ]);
        NavModule::updateOrCreate(['key' => 'parceiro'], [
            'label' => 'Parceiro', 'icon' => 'Handshake', 'sort_order' => $base + 2,
            'is_system' => true, 'active' => true, 'profiles' => ['parceiro_admin'], 'items' => $parceiroTree,
        ]);
    }

    public function down(): void
    {
        NavModule::whereIn('key', ['consultor', 'parceiro'])->delete();
    }
};
