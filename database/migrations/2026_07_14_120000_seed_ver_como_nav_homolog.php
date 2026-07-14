<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Traz a tela "Ver como" (/ver-como) para a rotina de menus (Configurador) no homolog.
 *
 * O controller (ImpersonationController) + rotas já existem no homolog; o que faltava
 * eram apenas os DADOS do Configurador que em produção já estavam no banco:
 *   1) registro da tela em `nav_screens` (perfis admin + administrativo);
 *   2) o nó do menu (`{screen: /ver-como, icon: Eye}`) nos módulos administrativo e
 *      configurador — por isso "não aparecia no menu".
 *
 * Idempotente e reversível: só insere o que faltar, não duplica.
 */
return new class extends Migration
{
    /** Módulos onde o item deve aparecer (espelha produção). */
    private array $modules = ['administrativo', 'administrativo__administrativo', 'configurador'];

    public function up(): void
    {
        // 1) Catálogo de telas do Configurador.
        if (! DB::table('nav_screens')->where('key', '/ver-como')->exists()) {
            DB::table('nav_screens')->insert([
                'key'        => '/ver-como',
                'label'      => 'Ver como',
                'route'      => '/ver-como',
                'active'     => true,
                'profiles'   => json_encode(['admin', 'administrativo']),
                'users'      => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2) Nó do menu por módulo (append só se ainda não houver a tela).
        foreach ($this->modules as $key) {
            $row = DB::table('nav_modules')->where('key', $key)->first();
            if (! $row) {
                continue;
            }
            $items = json_decode($row->items ?? '[]', true) ?: [];
            foreach ($items as $it) {
                if (($it['screen'] ?? null) === '/ver-como') {
                    continue 2; // já existe neste módulo
                }
            }
            $items[] = [
                'id'     => 'n_' . $key . '_vercomo',
                'icon'   => 'Eye',
                'screen' => '/ver-como',
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
                fn ($it) => ($it['screen'] ?? null) !== '/ver-como'
            ));
            DB::table('nav_modules')->where('key', $key)->update([
                'items'      => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        DB::table('nav_screens')->where('key', '/ver-como')->delete();
    }
};
