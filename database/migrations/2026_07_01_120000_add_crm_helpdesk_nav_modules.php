<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\NavModule;
use App\Models\NavScreen;

/**
 * Traz CRM e Help Desk para a rotina de menus (Configurador). Espelha a estrutura do NAV
 * hardcoded do FE: registra as telas em nav_screens (perfil admin) e cria os módulos
 * `crm` e `help_desk` (admin-owned). Demais perfis recebem por cópia no Configurador.
 */
return new class extends Migration
{
    public function up(): void
    {
        // key => label
        $crmScreens = [
            '/crm/pipeline' => 'Pipeline', '/crm/propostas/gestao' => 'Gestão de Propostas',
            '/crm/oportunidades' => 'Oportunidades', '/crm/leads' => 'Leads', '/crm/saude' => 'Saúde da Conta',
            '/crm/minha-carteira' => 'Minha Carteira', '/crm/forecast' => 'Forecast', '/crm/dashboards' => 'Dashboards',
            '/crm/tarefas' => 'Tarefas', '/crm/simulador' => 'Simulador',
            '/crm/empresas' => 'Empresas', '/crm/contatos' => 'Contatos',
            '/crm/pipelines' => 'Pipelines', '/crm/produtos' => 'Produtos e Serviços', '/crm/origens' => 'Origens',
            '/crm/motivos-perda' => 'Motivos de Perda', '/crm/tipos-contato' => 'Tipos de Contato',
            '/crm/responsaveis' => 'Responsáveis', '/crm/configuracoes/contratada' => 'Dados da Contratada',
            '/crm/configuracoes/templates' => 'Templates de Proposta',
        ];
        $hdScreens = [
            '/help-desk/operacoes' => 'Central de Operações', '/help-desk/tickets' => 'Chamados',
            '/help-desk/fila' => 'Fila (Kanban)', '/help-desk/kb' => 'Base de Conhecimento',
            '/help-desk/configuracoes?tab=categorias' => 'Categorias', '/help-desk/configuracoes?tab=servicos' => 'Serviços',
            '/help-desk/configuracoes?tab=justificativas' => 'Justificativas', '/help-desk/configuracoes?tab=status' => 'Status',
            '/help-desk/configuracoes?tab=filas' => 'Equipes', '/help-desk/configuracoes?tab=perfis' => 'Perfis de Acesso',
            '/help-desk/configuracoes?tab=pessoas' => 'Pessoas', '/help-desk/configuracoes?tab=associacoes' => 'Regras de Associação',
            '/help-desk/configuracoes?tab=contas-email' => 'Contas de E-mail', '/help-desk/configuracoes?tab=gatilhos' => 'Gatilhos (automação)',
            '/help-desk/configuracoes?tab=comunicacao' => 'Comunicação', '/help-desk/configuracoes?tab=sla' => 'SLA',
            '/help-desk/configuracoes?tab=tags' => 'Tags', '/help-desk/configuracoes?tab=playbooks' => 'Playbooks',
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
            // ações básicas no catálogo (se ainda não houver)
            $i = 0;
            foreach (['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir'] as $ak => $al) {
                DB::table('screen_actions')->updateOrInsert(
                    ['screen_key' => $key, 'action_key' => $ak],
                    ['label' => $al, 'sort_order' => $i++, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        };
        foreach ($crmScreens as $k => $l) $ensure($k, $l);
        foreach ($hdScreens as $k => $l) $ensure($k, $l);

        $nid = 0; $id = function () use (&$nid) { return 'n_cfg' . str_pad((string) (++$nid), 4, '0', STR_PAD_LEFT); };
        $grp = function (string $label, string $icon, array $keys) use ($id) {
            return ['id' => $id(), 'label' => $label, 'icon' => $icon,
                'children' => array_map(fn ($k) => ['id' => $id(), 'screen' => $k], $keys)];
        };

        $crmTree = [
            $grp('Comercial', 'Handshake', ['/crm/pipeline', '/crm/propostas/gestao', '/crm/oportunidades', '/crm/leads', '/crm/saude', '/crm/minha-carteira', '/crm/forecast', '/crm/dashboards', '/crm/tarefas', '/crm/simulador']),
            $grp('Cadastros CRM', 'Database', ['/crm/empresas', '/crm/contatos']),
            $grp('Configurações CRM', 'Settings', ['/crm/pipelines', '/crm/produtos', '/crm/origens', '/crm/motivos-perda', '/crm/tipos-contato', '/crm/responsaveis', '/crm/configuracoes/contratada', '/crm/configuracoes/templates']),
        ];
        $hdTree = [
            $grp('Help Desk', 'Headphones', ['/help-desk/operacoes', '/help-desk/tickets', '/help-desk/fila', '/help-desk/kb']),
            $grp('Configurações Help Desk', 'Settings', array_keys($hdScreens)),
        ];
        // a segunda pasta do HD deve ter só as telas de configuração (as 4 primeiras já estão na 1ª pasta)
        $hdTree[1]['children'] = array_slice($hdTree[1]['children'], 4);

        $sort = (int) NavModule::max('sort_order');
        NavModule::updateOrCreate(['key' => 'crm'], [
            'label' => 'CRM', 'icon' => 'Handshake', 'sort_order' => ++$sort,
            'is_system' => true, 'active' => true, 'profiles' => ['admin'], 'items' => $crmTree,
        ]);
        NavModule::updateOrCreate(['key' => 'help_desk'], [
            'label' => 'Help Desk', 'icon' => 'Headphones', 'sort_order' => ++$sort,
            'is_system' => true, 'active' => true, 'profiles' => ['admin'], 'items' => $hdTree,
        ]);
    }

    public function down(): void
    {
        NavModule::whereIn('key', ['crm', 'help_desk'])->delete();
    }
};
