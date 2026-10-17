<?php

use App\Models\NavModule;
use App\Models\NavScreen;
use Illuminate\Database\Migrations\Migration;

/**
 * HOTFIX: o seed do menu criou nav_screens com profiles/users NULL. O sidebar faz
 * conf.profiles.includes(...) sem guard → TypeError → o menu quebra ao montar após o login
 * (parece "credencial não funciona"). Reverte COMPLETAMENTE o seed (remove o nó dos módulos
 * Sistema e a linha nav_screens), voltando ao estado que funcionava. O item de menu será
 * reintroduzido depois com os campos corretos.
 */
return new class extends Migration
{
    private string $screen = '/ambientes/cadastros';

    public function up(): void
    {
        // Remove a linha problemática de nav_screens.
        if (\Illuminate\Support\Facades\Schema::hasTable('nav_screens')) {
            NavScreen::where('key', $this->screen)->delete();
        }

        // Remove o nó /ambientes/cadastros de qualquer módulo do menu.
        NavModule::all()->each(function (NavModule $m) {
            $items = $m->items ?? [];
            $stripped = $this->stripScreen($items, $this->screen);
            if ($stripped !== $items) {
                $m->items = $stripped;
                $m->save();
            }
        });
    }

    public function down(): void
    {
        // sem reversão (hotfix)
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
