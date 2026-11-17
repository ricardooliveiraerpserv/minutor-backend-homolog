<?php

use App\Models\NavModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de navegação "Financeiro" (launcher/sidebar), para admin e administrativo.
 * Reúne as telas financeiras que já existem (faturamento, pagamentos, rentabilidade) + os
 * Cadastros Financeiros (F1). As telas continuam também no Administrativo — nada é removido
 * de lá, exceto o grupo provisório "Financeiro" (n_fin) criado na migration 110000.
 * Idempotente: não recria o módulo se a key já existir (respeita ajustes feitos no Configurador).
 */
return new class extends Migration
{
    private string $key = 'financeiro';

    public function up(): void
    {
        if (! Schema::hasTable('nav_modules')) {
            return;
        }

        if (! NavModule::where('key', $this->key)->exists()) {
            $ordem = (int) NavModule::max('sort_order') + 1;
            $m = new NavModule();
            $m->key = $this->key;
            $m->label = 'Financeiro';
            $m->icon = 'Landmark';
            $m->sort_order = $ordem;
            $m->is_system = false;
            $m->active = true;
            $m->profiles = ['admin', 'administrativo'];
            $m->items = $this->items();
            $m->save();
        }

        // Remove o grupo provisório que a migration 110000 pôs no Administrativo.
        NavModule::whereIn('key', ['administrativo', 'administrativo__administrativo'])->get()
            ->each(function (NavModule $mod) {
                $items = $mod->items ?? [];
                $novo = array_values(array_filter($items, fn ($n) => ($n['id'] ?? null) !== 'n_fin'));
                if (count($novo) !== count($items)) {
                    $mod->items = $novo;
                    $mod->save();
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('nav_modules')) {
            NavModule::where('key', $this->key)->delete();
        }
    }

    private function items(): array
    {
        $g = fn (string $id, string $icon, string $label, array $telas) => [
            'id' => $id, 'icon' => $icon, 'label' => $label,
            'children' => array_map(fn ($t, $i) => ['id' => "{$id}_{$i}", 'icon' => $t[1], 'screen' => $t[0]], $telas, array_keys($telas)),
        ];

        return [
            $g('fin_fat', 'Receipt', 'Faturamento', [
                ['/faturamento-on-demand', 'Zap'],
                ['/fechamento/cliente', 'Building2'],
                ['/fechamento/excedentes', 'TrendingUp'],
                ['/fechamento/contratos', 'FileText'],
                ['/fechamento/reajustes', 'Percent'],
            ]),
            $g('fin_pag', 'Wallet', 'Pagamentos', [
                ['/fechamento/consultor', 'UserCheck'],
                ['/fechamento/parceiro', 'Handshake'],
                ['/fechamento/folha', 'ClipboardList'],
                ['/fechamento/diretoria', 'Briefcase'],
                ['/fechamento/adiantamentos', 'HandCoins'],
                ['/pagamento-despesas', 'Receipt'],
                ['/relatorios/pagamentos', 'FileBarChart'],
            ]),
            $g('fin_ana', 'ChartColumn', 'Análise', [
                ['/relatorios/rentabilidade', 'TrendingUp'],
                ['/fechamento', 'ChartColumn'],
            ]),
            $g('fin_cad', 'Settings2', 'Cadastros', [
                ['/financeiro/cadastros', 'Landmark'],
            ]),
        ];
    }
};
