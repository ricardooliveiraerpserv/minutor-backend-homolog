<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Coloca o PREVIEW do Cofre de Ambientes (/ambientes-preview) no menu como "Cofre de
 * Ambientes" enquanto validamos o layout (sem senha, dados mock). O módulo real /ambientes
 * segue fora do menu. Molde da 2026_07_25_210000. Idempotente e reversível.
 */
return new class extends Migration
{
    private array $internalProfiles = [
        'admin', 'administrativo',
        'coordenador_projetos', 'coordenador_sustentacao',
        'consultor', 'consultor_horista', 'consultor_banco_de_horas', 'consultor_fixo',
    ];

    private array $modules = [
        'administrativo', 'administrativo__administrativo',
        'servicos__coordenador_projetos', 'servicos__coordenador_sustentacao',
        'consultor__horista', 'consultor__banco_de_horas', 'consultor__fixo',
    ];

    public function up(): void
    {
        $now = now();
        $key = '/ambientes-preview';

        if (! DB::table('nav_screens')->where('key', $key)->exists()) {
            DB::table('nav_screens')->insert([
                'key' => $key, 'label' => 'Cofre de Ambientes', 'route' => $key, 'active' => true,
                'profiles' => json_encode($this->internalProfiles), 'users' => json_encode([]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ($this->modules as $mkey) {
            $row = DB::table('nav_modules')->where('key', $mkey)->first();
            if (! $row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            foreach ($items as $it) {
                if (($it['screen'] ?? null) === $key) {
                    continue 2;
                }
            }
            $items[] = ['id' => 'n_' . $mkey . '_ambientes_preview', 'icon' => 'Server', 'screen' => $key];
            DB::table('nav_modules')->where('key', $mkey)->update([
                'items' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $key = '/ambientes-preview';
        foreach ($this->modules as $mkey) {
            $row = DB::table('nav_modules')->where('key', $mkey)->first();
            if (! $row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            $items = array_values(array_filter($items, fn ($it) => ($it['screen'] ?? null) !== $key));
            DB::table('nav_modules')->where('key', $mkey)->update([
                'items' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
        DB::table('nav_screens')->where('key', $key)->delete();
    }
};
