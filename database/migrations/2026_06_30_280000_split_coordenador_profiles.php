<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Separa o perfil "coordenador" em "coordenador_projetos" e "coordenador_sustentacao"
 * (espelha User.coordinator_type) nas configs de permissão: nav_screens.profiles,
 * nav_screens.abilities[*].profiles e nav_modules.profiles. Acesso preservado p/ ambos.
 */
return new class extends Migration
{
    private array $split = ['coordenador_projetos', 'coordenador_sustentacao'];

    public function up(): void
    {
        // nav_screens.profiles
        foreach (DB::table('nav_screens')->get() as $s) {
            $profiles = json_decode($s->profiles ?? '[]', true) ?: [];
            $profiles = $this->convert($profiles);
            $abilities = json_decode($s->abilities ?? 'null', true);
            if (is_array($abilities)) {
                foreach ($abilities as $a => $cfg) {
                    if (isset($cfg['profiles']) && is_array($cfg['profiles'])) {
                        $abilities[$a]['profiles'] = $this->convert($cfg['profiles']);
                    }
                }
            }
            DB::table('nav_screens')->where('id', $s->id)->update([
                'profiles'  => json_encode($profiles),
                'abilities' => $abilities === null ? null : json_encode($abilities),
            ]);
        }

        // nav_modules.profiles
        foreach (DB::table('nav_modules')->get() as $m) {
            $profiles = json_decode($m->profiles ?? '[]', true) ?: [];
            DB::table('nav_modules')->where('id', $m->id)->update(['profiles' => json_encode($this->convert($profiles))]);
        }
    }

    private function convert(array $profiles): array
    {
        if (!in_array('coordenador', $profiles, true)) return array_values($profiles);
        $out = array_values(array_filter($profiles, fn ($p) => $p !== 'coordenador'));
        foreach ($this->split as $s) if (!in_array($s, $out, true)) $out[] = $s;
        return array_values($out);
    }

    public function down(): void
    {
        $revert = function (array $profiles): array {
            $has = array_intersect(['coordenador_projetos', 'coordenador_sustentacao'], $profiles);
            if (!$has) return array_values($profiles);
            $out = array_values(array_filter($profiles, fn ($p) => !in_array($p, ['coordenador_projetos', 'coordenador_sustentacao'], true)));
            if (!in_array('coordenador', $out, true)) $out[] = 'coordenador';
            return array_values($out);
        };
        foreach (DB::table('nav_screens')->get() as $s) {
            $profiles = $revert(json_decode($s->profiles ?? '[]', true) ?: []);
            $abilities = json_decode($s->abilities ?? 'null', true);
            if (is_array($abilities)) foreach ($abilities as $a => $cfg) if (isset($cfg['profiles'])) $abilities[$a]['profiles'] = $revert($cfg['profiles']);
            DB::table('nav_screens')->where('id', $s->id)->update(['profiles' => json_encode($profiles), 'abilities' => $abilities === null ? null : json_encode($abilities)]);
        }
        foreach (DB::table('nav_modules')->get() as $m) {
            DB::table('nav_modules')->where('id', $m->id)->update(['profiles' => json_encode($revert(json_decode($m->profiles ?? '[]', true) ?: []))]);
        }
    }
};
