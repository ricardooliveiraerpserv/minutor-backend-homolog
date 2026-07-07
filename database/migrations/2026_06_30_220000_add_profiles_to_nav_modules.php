<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acesso de PERFIL a MÓDULO passa a viver no próprio módulo (nav_modules.profiles) — definido
 * no Configurador, não mais na rotina de Cadastro de Perfil. Backfill invertendo a regra atual
 * (ProfileModule::forProfile). Admin é bypass (sempre vê tudo) → não precisa constar na lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('nav_modules', 'profiles')) {
            Schema::table('nav_modules', fn (Blueprint $t) => $t->json('profiles')->nullable()->after('active'));
        }

        // inverte forProfile: módulo → perfis (exclui admin = bypass, e cliente = portal)
        $profiles = ['administrativo', 'coordenador', 'consultor', 'parceiro_admin'];
        $byModule = [];
        foreach ($profiles as $p) {
            foreach (\App\Models\ProfileModule::forProfile($p) as $modKey) {
                $byModule[$modKey][] = $p;
            }
        }
        foreach (DB::table('nav_modules')->get() as $m) {
            DB::table('nav_modules')->where('id', $m->id)
                ->update(['profiles' => json_encode(array_values($byModule[$m->key] ?? []))]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nav_modules', 'profiles')) {
            Schema::table('nav_modules', fn (Blueprint $t) => $t->dropColumn('profiles'));
        }
    }
};
