<?php

use App\Models\NavModule;
use App\Models\NavScreen;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro F1 — registra a tela "Cadastros Financeiros" (/financeiro/cadastros) para admin e
 * administrativo e acrescenta um grupo "Financeiro" nos módulos do Administrativo. Idempotente:
 * só acrescenta o que não existe; não altera nenhum outro nó do menu.
 */
return new class extends Migration
{
    private string $screen = '/financeiro/cadastros';
    private array $modulos = ['administrativo', 'administrativo__administrativo'];

    public function up(): void
    {
        if (Schema::hasTable('nav_screens')) {
            $s = NavScreen::firstOrCreate(
                ['key' => $this->screen],
                ['label' => 'Cadastros Financeiros', 'route' => $this->screen, 'active' => true]
            );
            if (Schema::hasColumn('nav_screens', 'profiles') && empty($s->profiles)) {
                $s->profiles = ['admin', 'administrativo'];
                $s->save();
            }
        }

        if (! Schema::hasTable('nav_modules')) {
            return;
        }

        $grupo = [
            'id' => 'n_fin',
            'icon' => 'Landmark',
            'label' => 'Financeiro',
            'children' => [
                ['id' => 'n_fin_cad', 'icon' => 'Settings2', 'screen' => $this->screen],
            ],
        ];

        NavModule::whereIn('key', $this->modulos)->get()->each(function (NavModule $m) use ($grupo) {
            $items = $m->items ?? [];
            if ($this->hasScreen($items, $this->screen)) {
                return;
            }
            $items[] = $grupo;
            $m->items = $items;
            $m->save();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('nav_modules')) {
            NavModule::whereIn('key', $this->modulos)->get()->each(function (NavModule $m) {
                $m->items = array_values(array_filter($m->items ?? [], fn ($n) => ($n['id'] ?? null) !== 'n_fin'));
                $m->save();
            });
        }
        if (Schema::hasTable('nav_screens')) {
            NavScreen::where('key', $this->screen)->delete();
        }
    }

    private function hasScreen(array $nodes, string $screen): bool
    {
        foreach ($nodes as $n) {
            if (($n['screen'] ?? null) === $screen) return true;
            if (! empty($n['children']) && $this->hasScreen($n['children'], $screen)) return true;
        }
        return false;
    }
};
