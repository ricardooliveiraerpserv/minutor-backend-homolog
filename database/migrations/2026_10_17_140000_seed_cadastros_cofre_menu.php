<?php

use App\Models\NavModule;
use App\Models\NavScreen;
use Illuminate\Database\Migrations\Migration;

/**
 * Pré-posiciona a tela "Cadastros do Cofre" (/ambientes/cadastros) no grupo Sistema do
 * Configurador de Menus. Idempotente: só acrescenta se ainda não existir; não altera
 * nenhum outro nó. Seguro se o módulo Sistema não existir (não faz nada).
 */
return new class extends Migration
{
    private string $screen = '/ambientes/cadastros';

    public function up(): void
    {
        // Registra a tela (se o registro de telas for usado). Idempotente.
        if (\Illuminate\Support\Facades\Schema::hasTable('nav_screens')) {
            NavScreen::firstOrCreate(
                ['key' => $this->screen],
                ['label' => 'Cadastros do Cofre', 'route' => $this->screen, 'active' => true]
            );
        }

        $node = ['id' => 'scr-cadastros-cofre', 'screen' => $this->screen, 'label' => 'Cadastros do Cofre'];

        NavModule::all()->each(function (NavModule $m) use ($node) {
            if (strtolower(trim((string) $m->label)) !== 'sistema') return;
            $items = $m->items ?? [];
            if ($this->hasScreen($items, $this->screen)) return;
            $items[] = $node;
            $m->items = $items;
            $m->save();
        });
    }

    public function down(): void
    {
        NavModule::all()->each(function (NavModule $m) {
            if (strtolower(trim((string) $m->label)) !== 'sistema') return;
            $m->items = $this->stripScreen($m->items ?? [], $this->screen);
            $m->save();
        });
    }

    private function hasScreen(array $nodes, string $screen): bool
    {
        foreach ($nodes as $n) {
            if (($n['screen'] ?? null) === $screen) return true;
            if (! empty($n['children']) && $this->hasScreen($n['children'], $screen)) return true;
        }
        return false;
    }

    private function stripScreen(array $nodes, string $screen): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (($n['screen'] ?? null) === $screen) continue;
            if (! empty($n['children'])) $n['children'] = $this->stripScreen($n['children'], $screen);
            $out[] = $n;
        }
        return array_values($out);
    }
};
