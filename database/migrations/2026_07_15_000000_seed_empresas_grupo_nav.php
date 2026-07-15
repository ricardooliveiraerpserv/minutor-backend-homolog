<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Multi-empresa: registra a tela "Empresas do Grupo" (/configuracoes/empresas) na
 * rotina de menus (Configurador). O componente FE já existe (nav-catalog), faltavam
 * os DADOS do banco — por isso a tela não aparecia no Configurador nem no menu.
 * Registra em nav_screens (perfis admin + administrativo) e injeta o nó nos módulos
 * administrativo/configurador. Idempotente e reversível.
 */
return new class extends Migration
{
    private string $screen = '/configuracoes/empresas';

    private array $modules = ['administrativo', 'administrativo__administrativo', 'configurador'];

    public function up(): void
    {
        if (! DB::table('nav_screens')->where('key', $this->screen)->exists()) {
            DB::table('nav_screens')->insert([
                'key'        => $this->screen,
                'label'      => 'Empresas do Grupo',
                'route'      => $this->screen,
                'active'     => true,
                'profiles'   => json_encode(['admin', 'administrativo']),
                'users'      => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($this->modules as $key) {
            $row = DB::table('nav_modules')->where('key', $key)->first();
            if (! $row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            foreach ($items as $it) {
                if (($it['screen'] ?? null) === $this->screen) {
                    continue 2; // já existe neste módulo
                }
            }
            $items[] = [
                'id'     => 'n_' . $key . '_empresas_grupo',
                'icon'   => 'Building2',
                'screen' => $this->screen,
            ];
            DB::table('nav_modules')->where('key', $key)->update([
                'items'      => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
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
            $items = array_values(array_filter(
                $items,
                fn ($it) => ($it['screen'] ?? null) !== $this->screen
            ));
            DB::table('nav_modules')->where('key', $key)->update([
                'items'      => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        DB::table('nav_screens')->where('key', $this->screen)->delete();
    }
};
