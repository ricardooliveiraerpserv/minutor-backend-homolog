<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HOMOLOG: vincula os usuários INTERNOS (admin/administrativo/coordenador) também
 * à BIZIFY, para o seletor de empresa aparecer (o FE só o mostra com 2+ empresas)
 * e o time conseguir TESTAR a troca de empresa. Não mexe em `is_bizify` (evita
 * efeitos colaterais em folha/partição) — só cria o vínculo company_user.
 *
 * Gated ao homolog (existência dos módulos crm/help_desk). Idempotente.
 * REQUER `MULTIEMPRESA_SCOPING=true` no ambiente pra o scoping de fato agir;
 * sem o flag, o vínculo é inócuo (seletor aparece, troca é cosmética).
 */
return new class extends Migration
{
    public function up(): void
    {
        $isHomolog = DB::table('nav_modules')->whereIn('key', ['crm', 'help_desk'])->exists();
        if (! $isHomolog) {
            return;
        }
        $bizifyId = DB::table('companies')->where('slug', 'bizify')->value('id');
        if (! $bizifyId) {
            return;
        }

        DB::table('users')
            ->whereIn('type', ['admin', 'administrativo', 'coordenador'])
            ->orderBy('id')
            ->select('id', 'type')
            ->chunk(500, function ($users) use ($bizifyId) {
                foreach ($users as $u) {
                    DB::table('company_user')->updateOrInsert(
                        ['user_id' => $u->id, 'company_id' => $bizifyId],
                        ['role' => $u->type, 'updated_at' => now(), 'created_at' => now()]
                    );
                }
            });
    }

    public function down(): void
    {
        $bizifyId = DB::table('companies')->where('slug', 'bizify')->value('id');
        if ($bizifyId) {
            // Remove só os vínculos criados aqui (internos → BIZIFY); mantém quem é is_bizify legítimo.
            $internalIds = DB::table('users')->whereIn('type', ['admin', 'administrativo', 'coordenador'])
                ->where('is_bizify', false)->pluck('id');
            DB::table('company_user')->where('company_id', $bizifyId)->whereIn('user_id', $internalIds)->delete();
        }
    }
};
