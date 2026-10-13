<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faturamento On Demand: registra a tela /faturamento-on-demand na rotina de menus e
 * injeta o item nos módulos de admin/administrativo e dos coordenadores (projetos e
 * sustentação). Molde de 2026_07_25_210000_add_environments_nav_screens. Idempotente.
 */
return new class extends Migration
{
    private string $screenKey = '/faturamento-on-demand';
    private string $screenLabel = 'Faturamento On Demand';

    private array $profiles = [
        'admin', 'administrativo', 'coordenador_projetos', 'coordenador_sustentacao',
    ];

    private array $modules = [
        'administrativo', 'administrativo__administrativo',
        'servicos__coordenador_projetos', 'servicos__coordenador_sustentacao',
    ];

    public function up(): void
    {
        $now = now();

        if (! DB::table('nav_screens')->where('key', $this->screenKey)->exists()) {
            DB::table('nav_screens')->insert([
                'key' => $this->screenKey, 'label' => $this->screenLabel, 'route' => $this->screenKey,
                'active' => true, 'profiles' => json_encode($this->profiles), 'users' => json_encode([]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $i = 0;
        foreach (['view' => 'Visualizar', 'release' => 'Liberar faturamento'] as $ak => $al) {
            DB::table('screen_actions')->updateOrInsert(
                ['screen_key' => $this->screenKey, 'action_key' => $ak],
                ['label' => $al, 'sort_order' => $i++, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        foreach ($this->modules as $key) {
            $row = DB::table('nav_modules')->where('key', $key)->first();
            if (! $row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            $jaTem = false;
            array_walk_recursive($items, function ($v) use (&$jaTem) {
                if ($v === $this->screenKey) {
                    $jaTem = true;
                }
            });
            if ($jaTem) {
                continue;
            }
            $items[] = ['id' => 'n_' . $key . '_faturamento_ondemand', 'icon' => 'Receipt', 'screen' => $this->screenKey];
            DB::table('nav_modules')->where('key', $key)->update([
                'items' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->modules as $key) {
            $row = DB::table('nav_modules')->where('key', $key)->first();
            if (! $row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            $items = array_values(array_filter($items, fn ($it) => ($it['screen'] ?? null) !== $this->screenKey));
            DB::table('nav_modules')->where('key', $key)->update([
                'items' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
        DB::table('screen_actions')->where('screen_key', $this->screenKey)->delete();
        DB::table('nav_screens')->where('key', $this->screenKey)->delete();
    }
};
