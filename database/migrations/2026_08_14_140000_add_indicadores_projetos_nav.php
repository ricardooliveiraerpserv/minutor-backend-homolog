<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cronograma (merge): adiciona "Indicadores de Projetos" (/projetos/indicadores) ao menu
 * do módulo Serviços, no grupo Projetos (logo após "Demandas e Projetos"). O label vem do
 * nav-catalog do FE. Idempotente: não duplica se já existir. Robusto: localiza o grupo pelo
 * child /contratos/pipeline (não depende do id fixo do grupo).
 */
return new class extends Migration
{
    private const SCREEN = '/projetos/indicadores';
    private const ANCHOR = '/contratos/pipeline';
    private const CHILD  = ['id' => 'n_servicos_indicadores', 'icon' => 'BarChart2', 'screen' => self::SCREEN];

    public function up(): void
    {
        $row = DB::table('nav_modules')->where('key', 'servicos')->first();
        if (! $row) return;

        $items = json_decode($row->items ?? '[]', true) ?: [];
        $changed = false;

        foreach ($items as &$g) {
            $children = $g['children'] ?? [];
            $screens  = array_column($children, 'screen');
            // grupo alvo = o que tem /contratos/pipeline e ainda NÃO tem os indicadores
            if (in_array(self::ANCHOR, $screens, true) && ! in_array(self::SCREEN, $screens, true)) {
                $out = [];
                foreach ($children as $c) {
                    $out[] = $c;
                    if (($c['screen'] ?? null) === self::ANCHOR) {
                        $out[] = self::CHILD; // insere logo após "Demandas e Projetos"
                    }
                }
                $g['children'] = $out;
                $changed = true;
            }
        }
        unset($g);

        if ($changed) {
            DB::table('nav_modules')->where('key', 'servicos')->update([
                'items'      => json_encode($items, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $row = DB::table('nav_modules')->where('key', 'servicos')->first();
        if (! $row) return;

        $items = json_decode($row->items ?? '[]', true) ?: [];
        foreach ($items as &$g) {
            if (! empty($g['children'])) {
                $g['children'] = array_values(array_filter(
                    $g['children'],
                    fn ($c) => ($c['screen'] ?? null) !== self::SCREEN
                ));
            }
        }
        unset($g);

        DB::table('nav_modules')->where('key', 'servicos')->update([
            'items'      => json_encode($items, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
