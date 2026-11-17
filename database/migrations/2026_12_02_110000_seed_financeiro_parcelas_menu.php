<?php

use App\Models\NavModule;
use App\Models\NavScreen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * F2 — tela "Parcelas" (/financeiro/parcelas) no grupo Faturamento do módulo Financeiro (1º item).
 * Idempotente; não mexe em outros nós.
 */
return new class extends Migration
{
    private string $screen = '/financeiro/parcelas';

    public function up(): void
    {
        if (Schema::hasTable('nav_screens')) {
            $s = NavScreen::firstOrCreate(['key' => $this->screen], ['label' => 'Parcelas a faturar', 'route' => $this->screen, 'active' => true]);
            if (Schema::hasColumn('nav_screens', 'profiles') && empty($s->profiles)) {
                $s->profiles = ['admin', 'administrativo'];
                $s->save();
            }
        }
        if (! Schema::hasTable('nav_modules')) return;

        $m = NavModule::where('key', 'financeiro')->first();
        if (! $m) return;
        $items = $m->items ?? [];
        if ($this->hasScreen($items, $this->screen)) return;

        foreach ($items as &$g) {
            if (($g['id'] ?? null) === 'fin_fat') {
                array_unshift($g['children'], ['id' => 'fin_fat_parcelas', 'icon' => 'CalendarClock', 'screen' => $this->screen]);
                $m->items = $items;
                $m->save();
                return;
            }
        }
        unset($g);
        // grupo Faturamento foi removido/renomeado no Configurador: cria no topo
        array_unshift($items, ['id' => 'fin_fat_parcelas', 'icon' => 'CalendarClock', 'screen' => $this->screen]);
        $m->items = $items;
        $m->save();
    }

    public function down(): void
    {
        if (Schema::hasTable('nav_modules') && ($m = NavModule::where('key', 'financeiro')->first())) {
            $m->items = $this->strip($m->items ?? []);
            $m->save();
        }
        if (Schema::hasTable('nav_screens')) NavScreen::where('key', $this->screen)->delete();
    }

    private function hasScreen(array $nodes, string $screen): bool
    {
        foreach ($nodes as $n) {
            if (($n['screen'] ?? null) === $screen) return true;
            if (! empty($n['children']) && $this->hasScreen($n['children'], $screen)) return true;
        }
        return false;
    }

    private function strip(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (($n['screen'] ?? null) === $this->screen) continue;
            if (! empty($n['children'])) $n['children'] = $this->strip($n['children']);
            $out[] = $n;
        }
        return array_values($out);
    }
};
