<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restaura a ÁRVORE canônica de navegação (administrativo/servicos) espelhando os grupos da
 * sidebar. Reaplicável: reconstrói nav_modules.items e cria nav_screens faltantes SEM tocar
 * nas permissões existentes. Uso: `php artisan nav:reset-tree`.
 */
class NavResetTree extends Command
{
    protected $signature = 'nav:reset-tree';
    protected $description = 'Restaura a árvore de menus (administrativo/servicos) ao padrão do sistema';

    public function handle(): int
    {
        $tree = [
            'servicos' => [
                ['group' => 'Projetos', 'icon' => 'Layers', 'children' => ['/contratos/pipeline', '/investimento-comercial']],
                ['group' => 'Sustentação', 'icon' => 'Headphones', 'children' => ['/sustentacao']],
                ['group' => 'Operação', 'icon' => 'Clock', 'children' => ['/timesheets', '/expenses', '/relatorios/apontamentos', '/approvals', '/timesheets/atrasos', '/auditoria/apontamentos']],
            ],
            'administrativo' => [
                ['group' => 'Gestão Contratual', 'icon' => 'Layers', 'children' => ['/gestao-projetos', '/contratos/kanban']],
                ['group' => 'Financeiro', 'icon' => 'DollarSign', 'children' => [
                    ['group' => 'Fechamento', 'icon' => 'DollarSign', 'children' => ['/fechamento', '/fechamento/cliente', '/fechamento/parceiro', '/fechamento/consultor', '/fechamento/adiantamentos', '/fechamento/diretoria', '/fechamento/folha', '/fechamento/contratos', '/fechamento/reajustes', '/pagamento-despesas']],
                ]],
                ['group' => 'Relatórios', 'icon' => 'FileText', 'children' => ['/relatorios/pagamentos', '/relatorios/rentabilidade/consultor', '/relatorios/rentabilidade/projeto', '/relatorios/rentabilidade', '/relatorios/contratos-sem-vencimento']],
                ['group' => 'Cadastros', 'icon' => 'Database', 'children' => ['/clientes', '/cadastros?tab=executives', '/partners', '/cadastros?tab=payment_methods', '/cadastros?tab=holidays', '/cadastros?tab=contracts', '/cadastros?tab=services', '/cadastros?tab=expense_types', '/cadastros?tab=expense_categories', '/cadastros?tab=groups', '/cadastros?tab=customer_contacts', '/cadastros/saldo-inicial-tickets', '/configuracoes/movidesk']],
                ['group' => 'Comunicação', 'icon' => 'Mail', 'children' => ['/central-comunicacao', '/cadastros?tab=email_templates', '/cadastros/workflows']],
                ['group' => 'Visão Externa', 'icon' => 'BarChart2', 'children' => [
                    ['group' => 'Cliente', 'icon' => 'Building2', 'children' => ['/portal-cliente', '/dashboards/bank-hours-fixed', '/dashboards/bank-hours-monthly', '/dashboards/on-demand', '/dashboards/fechado']],
                    ['group' => 'Consultor', 'icon' => 'UserCheck', 'children' => ['/meu-painel']],
                    ['group' => 'Parceiro', 'icon' => 'Handshake', 'children' => ['/partner-dashboard']],
                ]],
                ['group' => 'Sistema', 'icon' => 'Settings', 'children' => [
                    ['group' => 'Configurações', 'icon' => 'Settings', 'children' => ['/settings', '/users', '/settings?tab=cargos']],
                ]],
            ],
        ];

        $internal = ['admin', 'administrativo', 'coordenador_projetos', 'coordenador_sustentacao', 'consultor', 'parceiro_admin'];
        $now = now();

        $ensureScreen = function (string $href) use ($internal, $now) {
            if (!DB::table('nav_screens')->where('key', $href)->exists()) {
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
                        $node = ['id' => 'g_' . $modKey . '_' . ($seq++), 'label' => $it['group']];
                        if (!empty($it['icon'])) $node['icon'] = $it['icon'];
                        $node['children'] = $build($it['children'] ?? []);
                        $out[] = $node;
                    } else {
                        $href = is_array($it) ? ($it['screen'] ?? null) : $it;
                        if (!$href) continue;
                        $ensureScreen($href);
                        $out[] = ['id' => 'n_' . $modKey . '_' . ($seq++), 'screen' => $href];
                    }
                }
                return $out;
            };
            DB::table('nav_modules')->where('key', $modKey)->update(['items' => json_encode($build($entries))]);
            $this->info("✓ {$modKey} restaurado");
        }

        return self::SUCCESS;
    }
}
