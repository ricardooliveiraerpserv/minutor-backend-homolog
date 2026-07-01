<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Converte nav_modules.items de ["/href", ...] para objetos ricos:
 *   { key, label?, icon?, active, profiles:[], users:[] }
 * Default: ativo, visível a todos os perfis internos, sem override de usuário.
 */
return new class extends Migration
{
    public function up(): void
    {
        $internal = ['admin', 'administrativo', 'coordenador', 'consultor', 'parceiro_admin'];
        foreach (DB::table('nav_modules')->get() as $m) {
            $items = json_decode($m->items ?? '[]', true) ?: [];
            $rich = array_map(function ($it) use ($internal) {
                if (is_array($it)) return $it; // já convertido
                return ['key' => $it, 'active' => true, 'profiles' => $internal, 'users' => []];
            }, $items);
            DB::table('nav_modules')->where('id', $m->id)->update(['items' => json_encode($rich)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('nav_modules')->get() as $m) {
            $items = json_decode($m->items ?? '[]', true) ?: [];
            $keys = array_map(fn ($it) => is_array($it) ? ($it['key'] ?? '') : $it, $items);
            DB::table('nav_modules')->where('id', $m->id)->update(['items' => json_encode(array_values(array_filter($keys)))]);
        }
    }
};
