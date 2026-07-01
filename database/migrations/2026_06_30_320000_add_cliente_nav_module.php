<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\NavModule;
use App\Models\NavScreen;

/**
 * Módulo "cliente" no Configurador (aba separada) — espelha o menu do Portal do Cliente.
 * Não entra no launcher/sidebar interno (module-context exclui 'cliente'); serve p/ gerenciar
 * o menu do cliente no Configurador. Telas ganham o perfil 'cliente' (sem remover os demais).
 */
return new class extends Migration
{
    public function up(): void
    {
        $screens = [
            '/portal-cliente'                 => 'Home',
            '/comunicados'                    => 'Comunicados',
            '/contratos/pipeline'             => 'Demandas e Projetos',
            '/help-desk/portal'               => 'Central de Atendimento',
            '/dashboards/bank-hours-fixed'    => 'Banco de Horas Fixo',
            '/dashboards/bank-hours-monthly'  => 'Banco de Horas Mensais',
            '/dashboards/on-demand'           => 'On Demand',
            '/dashboards/fechado'             => 'Fechado',
        ];
        foreach ($screens as $key => $label) {
            $s = NavScreen::firstOrNew(['key' => $key]);
            $profiles = $s->exists ? ($s->profiles ?? []) : [];
            if (!in_array('cliente', $profiles, true)) $profiles[] = 'cliente';
            $s->profiles = array_values($profiles);
            $s->route  = $s->route ?: $key;
            if (!$s->exists) $s->active = true;
            if (empty($s->label)) $s->label = $label;
            $s->users = $s->users ?? [];
            $s->save();
        }

        $tree = [
            ['id' => 'n_cliente_0', 'screen' => '/portal-cliente'],
            ['id' => 'n_cliente_1', 'screen' => '/comunicados'],
            ['id' => 'n_cliente_2', 'screen' => '/contratos/pipeline'],
            ['id' => 'n_cliente_3', 'screen' => '/help-desk/portal'],
            ['id' => 'g_cliente_4', 'label' => 'Contratos', 'icon' => 'FileText', 'children' => [
                ['id' => 'n_cliente_5', 'screen' => '/dashboards/bank-hours-fixed'],
                ['id' => 'n_cliente_6', 'screen' => '/dashboards/bank-hours-monthly'],
                ['id' => 'n_cliente_7', 'screen' => '/dashboards/on-demand'],
                ['id' => 'n_cliente_8', 'screen' => '/dashboards/fechado'],
            ]],
        ];

        NavModule::updateOrCreate(['key' => 'cliente'], [
            'label'      => 'Cliente',
            'icon'       => 'Building2',
            'sort_order' => (int) (NavModule::max('sort_order') + 1),
            'is_system'  => true,
            'active'     => true,
            'profiles'   => ['cliente'],
            'items'      => $tree,
        ]);
    }

    public function down(): void
    {
        NavModule::where('key', 'cliente')->delete();
    }
};
