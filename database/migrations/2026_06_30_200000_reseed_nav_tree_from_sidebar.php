<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reseed da ESTRUTURA (árvore) dos módulos administrativo/servicos espelhando os GRUPOS reais
 * da sidebar (Gestão Contratual, Financeiro › Fechamento, Relatórios, Cadastros, Comunicação,
 * Visão Externa › Cliente/Consultor/Parceiro, Sistema; Serviços: Projetos, Sustentação, Operação).
 * Telas viram referências aninhadas dentro dos grupos. Permissões ficam em nav_screens (não toca
 * nas existentes; cria as faltantes com default = todos os perfis internos).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tree = [
            'servicos' => [
                ['group' => 'Projetos',    'children' => ['/contratos/pipeline', '/investimento-comercial']],
                ['group' => 'Sustentação', 'children' => ['/sustentacao']],
                ['group' => 'Operação',    'children' => ['/timesheets', '/expenses', '/relatorios/apontamentos', '/approvals', '/timesheets/atrasos', '/auditoria/apontamentos']],
            ],
            'administrativo' => [
                ['group' => 'Gestão Contratual', 'children' => ['/gestao-projetos', '/contratos/kanban']],
                ['group' => 'Financeiro', 'children' => [
                    ['group' => 'Fechamento', 'children' => ['/fechamento', '/fechamento/cliente', '/fechamento/parceiro', '/fechamento/consultor', '/fechamento/adiantamentos', '/fechamento/diretoria', '/fechamento/folha', '/fechamento/contratos', '/fechamento/reajustes', '/pagamento-despesas']],
                ]],
                ['group' => 'Relatórios', 'children' => ['/relatorios/pagamentos', '/relatorios/rentabilidade/consultor', '/relatorios/rentabilidade/projeto', '/relatorios/rentabilidade', '/relatorios/contratos-sem-vencimento']],
                ['group' => 'Cadastros', 'children' => ['/clientes', '/cadastros?tab=executives', '/partners', '/cadastros?tab=payment_methods', '/cadastros?tab=holidays', '/cadastros?tab=contracts', '/cadastros?tab=services', '/cadastros?tab=expense_types', '/cadastros?tab=expense_categories', '/cadastros?tab=groups', '/cadastros?tab=customer_contacts', '/cadastros/saldo-inicial-tickets', '/configuracoes/movidesk']],
                ['group' => 'Comunicação', 'children' => ['/central-comunicacao', '/cadastros?tab=email_templates', '/cadastros/workflows']],
                ['group' => 'Visão Externa', 'children' => [
                    ['group' => 'Cliente',   'children' => ['/portal-cliente', '/dashboards/bank-hours-fixed', '/dashboards/bank-hours-monthly', '/dashboards/on-demand', '/dashboards/fechado']],
                    ['group' => 'Consultor', 'children' => ['/meu-painel']],
                    ['group' => 'Parceiro',  'children' => ['/partner-dashboard']],
                ]],
                ['group' => 'Sistema', 'children' => ['/users', '/settings', '/settings?tab=perfis']],
            ],
        ];

        $internal = ['admin', 'administrativo', 'coordenador', 'consultor', 'parceiro_admin'];
        $now = now();

        // garante que toda tela referenciada exista em nav_screens (sem clobber das existentes)
        $ensureScreen = function (string $href) use ($internal, $now) {
            $exists = DB::table('nav_screens')->where('key', $href)->exists();
            if (!$exists) {
                DB::table('nav_screens')->insert([
                    'key' => $href, 'label' => null, 'route' => $href, 'active' => true,
                    'profiles' => json_encode($internal), 'users' => json_encode([]),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        };

        foreach ($tree as $modKey => $entries) {
            $seq = 0;
            $build = function ($items) use (&$build, &$seq, $modKey, $ensureScreen) {
                $out = [];
                foreach ($items as $it) {
                    if (is_array($it) && isset($it['group'])) {
                        $out[] = [
                            'id'       => 'g_' . $modKey . '_' . ($seq++),
                            'label'    => $it['group'],
                            'children' => $build($it['children'] ?? []),
                        ];
                    } else {
                        $href = is_array($it) ? ($it['screen'] ?? null) : $it;
                        if (!$href) continue;
                        $ensureScreen($href);
                        $out[] = ['id' => 'n_' . $modKey . '_' . ($seq++), 'screen' => $href];
                    }
                }
                return $out;
            };

            $items = $build($entries);
            DB::table('nav_modules')->where('key', $modKey)->update(['items' => json_encode($items)]);
        }
    }

    public function down(): void
    {
        // sem rollback estrutural — a árvore anterior (chapada) pode ser remontada manualmente
    }
};
