<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-empresa no Help Desk — backfill do escopo de EMPRESAS por perfil de acesso.
 *
 * Cada Perfil de Acesso passa a definir quais empresas o agente atende, guardado em
 * permissions['policies.companies'] (array de company_id). Este backfill semeia cada perfil
 * EXISTENTE com a empresa ATUAL dele (helpdesk_access_profiles.company_id) — mantém a
 * segregação de hoje (nada muda até alguém editar o perfil e marcar mais empresas).
 *
 * Idempotente: só preenche quando a chave ainda não existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_access_profiles')) return;

        DB::table('helpdesk_access_profiles')->orderBy('id')->each(function ($p) {
            $perms = json_decode($p->permissions ?? '[]', true);
            if (!is_array($perms)) $perms = [];

            // Já definido → não mexe (idempotência).
            if (array_key_exists('policies.companies', $perms) && is_array($perms['policies.companies']) && count($perms['policies.companies'])) {
                return;
            }

            // Semeia com a empresa atual do perfil (se houver). Sem company_id, deixa vazio
            // (o fallback da policy resolve pelas empresas do usuário).
            $perms['policies.companies'] = $p->company_id ? [(int) $p->company_id] : [];

            DB::table('helpdesk_access_profiles')->where('id', $p->id)
                ->update(['permissions' => json_encode($perms), 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('helpdesk_access_profiles')) return;

        // Remove só a chave introduzida por este backfill (preserva o resto do JSON).
        DB::table('helpdesk_access_profiles')->orderBy('id')->each(function ($p) {
            $perms = json_decode($p->permissions ?? '[]', true);
            if (is_array($perms) && array_key_exists('policies.companies', $perms)) {
                unset($perms['policies.companies']);
                DB::table('helpdesk_access_profiles')->where('id', $p->id)
                    ->update(['permissions' => json_encode($perms), 'updated_at' => now()]);
            }
        });
    }
};
