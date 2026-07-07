<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\NavModule;

/**
 * Consultor e Parceiro passaram a ter MÓDULO PRÓPRIO no Configurador (abas separadas).
 * Removê-los de `servicos.profiles` evita que a aba desses perfis mostre também "Serviços".
 * Não afeta a navegação real deles (sidebar própria/hardcoded).
 */
return new class extends Migration
{
    public function up(): void
    {
        $m = NavModule::where('key', 'servicos')->first();
        if (!$m) return;
        $m->profiles = array_values(array_diff($m->profiles ?? [], ['consultor', 'parceiro_admin']));
        $m->save();
    }

    public function down(): void
    {
        $m = NavModule::where('key', 'servicos')->first();
        if (!$m) return;
        $m->profiles = array_values(array_unique(array_merge($m->profiles ?? [], ['consultor', 'parceiro_admin'])));
        $m->save();
    }
};
