<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\NavModule;
use App\Models\NavScreen;

/**
 * Registra o módulo Help Desk (nav_modules) + suas telas (nav_screens) no dev2.
 * O nav é dinâmico (Configurador) e veio do dump de prod, que não tinha o HD;
 * esta migration insere o módulo + o menu COMPLETO atual (inclui Entregas vencidas
 * e Solicitar Código-Fonte). Idempotente (updateOrCreate).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Telas do menu principal do HD (key => label)
        $hdMain = [
            '/help-desk/operacoes'          => 'Central de Operações',
            '/help-desk/tickets'            => 'Chamados',
            '/help-desk/entregas-vencidas'  => 'Entregas vencidas',
            '/help-desk/codigo-fonte'       => 'Solicitar Código-Fonte',
            '/help-desk/fila'               => 'Fila (Kanban)',
            '/help-desk/kb'                 => 'Base de Conhecimento',
        ];
        // Telas de configuração do HD (tab)
        $hdConfig = [
            '/help-desk/configuracoes?tab=categorias'   => 'Categorias',
            '/help-desk/configuracoes?tab=servicos'     => 'Serviços',
            '/help-desk/configuracoes?tab=justificativas' => 'Justificativas',
            '/help-desk/configuracoes?tab=status'       => 'Status',
            '/help-desk/configuracoes?tab=filas'        => 'Equipes',
            '/help-desk/configuracoes?tab=perfis'       => 'Perfis de Acesso',
            '/help-desk/configuracoes?tab=departamentos' => 'Departamentos',
            '/help-desk/configuracoes?tab=associacoes'  => 'Regras de Associação',
            '/help-desk/configuracoes?tab=contas-email' => 'Contas de E-mail',
            '/help-desk/configuracoes?tab=gatilhos'     => 'Gatilhos (automação)',
            '/help-desk/configuracoes?tab=comunicacao'  => 'Comunicação',
            '/help-desk/configuracoes?tab=sla'          => 'SLA',
            '/help-desk/configuracoes?tab=tags'         => 'Tags',
            '/help-desk/configuracoes?tab=playbooks'    => 'Playbooks',
        ];

        $now = now();
        $ensure = function (string $key, string $label) use ($now) {
            $s = NavScreen::firstOrNew(['key' => $key]);
            $profiles = $s->exists ? ($s->profiles ?? []) : [];
            if (!in_array('admin', $profiles, true)) $profiles[] = 'admin';
            $s->profiles = array_values($profiles);
            $s->route = $s->route ?: $key;
            if (!$s->exists) $s->active = true;
            if (empty($s->label)) $s->label = $label;
            $s->users = $s->users ?? [];
            $s->save();
            $i = 0;
            foreach (['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'] as $ak => $al) {
                DB::table('screen_actions')->updateOrInsert(
                    ['screen_key' => $key, 'action_key' => $ak],
                    ['label' => $al, 'sort_order' => $i++, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        };
        foreach ($hdMain as $k => $l) $ensure($k, $l);
        foreach ($hdConfig as $k => $l) $ensure($k, $l);

        $nid = 0; $id = function () use (&$nid) { return 'n_hd' . str_pad((string) (++$nid), 4, '0', STR_PAD_LEFT); };
        $grp = function (string $label, string $icon, array $keys) use ($id) {
            return ['id' => $id(), 'label' => $label, 'icon' => $icon,
                'children' => array_map(fn ($k) => ['id' => $id(), 'screen' => $k], $keys)];
        };

        $hdTree = [
            $grp('Help Desk', 'Headphones', array_keys($hdMain)),
            $grp('Configurações Help Desk', 'Settings', array_keys($hdConfig)),
        ];

        $sort = (int) NavModule::max('sort_order');
        NavModule::updateOrCreate(['key' => 'help_desk'], [
            'label' => 'Help Desk', 'icon' => 'Headphones', 'sort_order' => ++$sort,
            'is_system' => true, 'active' => true, 'profiles' => ['admin'], 'items' => $hdTree,
        ]);
    }

    public function down(): void
    {
        NavModule::where('key', 'help_desk')->delete();
    }
};
