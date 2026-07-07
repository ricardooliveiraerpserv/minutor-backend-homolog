<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-seed dos módulos para nível ITEM (key = href de cada tela), substituindo o nível-grupo.
 * Só atualiza os 3 módulos de sistema; módulos custom criados pelo admin ficam intactos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $servicos = [
            '/contratos/pipeline', '/investimento-comercial', '/sustentacao',
            '/timesheets', '/expenses', '/approvals', '/timesheets/atrasos',
            '/auditoria/apontamentos', '/relatorios/apontamentos',
        ];
        $administrativo = [
            '/gestao-projetos', '/contratos/kanban',
            '/fechamento', '/fechamento/cliente', '/fechamento/parceiro', '/fechamento/consultor',
            '/fechamento/adiantamentos', '/fechamento/diretoria', '/fechamento/folha',
            '/fechamento/contratos', '/fechamento/reajustes', '/pagamento-despesas',
            '/relatorios/pagamentos', '/relatorios/rentabilidade/consultor', '/relatorios/rentabilidade/projeto',
            '/relatorios/rentabilidade', '/relatorios/contratos-sem-vencimento',
            '/clientes', '/partners', '/cadastros?tab=executives', '/cadastros?tab=payment_methods',
            '/cadastros?tab=holidays', '/cadastros?tab=contracts', '/cadastros?tab=services',
            '/cadastros?tab=expense_types', '/cadastros?tab=expense_categories', '/cadastros?tab=groups',
            '/cadastros?tab=customer_contacts', '/cadastros/saldo-inicial-tickets', '/configuracoes/movidesk',
            '/central-comunicacao', '/cadastros?tab=email_templates', '/cadastros/workflows',
            '/portal-cliente', '/dashboards/bank-hours-fixed', '/dashboards/bank-hours-monthly',
            '/dashboards/on-demand', '/dashboards/fechado', '/partner-dashboard', '/users', '/settings',
        ];
        $configurador = ['/configurador', '/settings?tab=perfis'];

        DB::table('nav_modules')->where('key', 'servicos')->update(['items' => json_encode($servicos)]);
        DB::table('nav_modules')->where('key', 'administrativo')->update(['items' => json_encode($administrativo)]);
        DB::table('nav_modules')->where('key', 'configurador')->update(['items' => json_encode($configurador)]);
    }

    public function down(): void
    {
        // volta ao nível-grupo (não crítico)
        DB::table('nav_modules')->where('key', 'servicos')->update(['items' => json_encode(['projetos', 'sustentacao', 'operacao'])]);
        DB::table('nav_modules')->where('key', 'administrativo')->update(['items' => json_encode(['gestao_contratual', 'financeiro', 'relatorios', 'cadastros', 'comunicacao', 'visao_externa', 'sistema'])]);
        DB::table('nav_modules')->where('key', 'configurador')->update(['items' => json_encode(['configurador'])]);
    }
};
