<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trava: um DOMÍNIO pertence a UMA única empresa nas Regras de Associação do Help Desk.
 *
 * O model usa CompanyScope (isolamento por empresa), então o guard do controller só via
 * a empresa ativa — permitindo o mesmo domínio em ERPSERV e BIZIFY. Resultado: 12 domínios
 * duplicados (regra original na empresa 1 com perfil + duplicata na empresa 2 sem perfil).
 *
 * Esta migration:
 *  1) Resolve duplicatas: para cada domínio com mais de uma regra ativa, MANTÉM a da empresa 1
 *     (ERPSERV) de menor id — senão a de menor id — e faz soft-delete das demais.
 *  2) Cria índice ÚNICO PARCIAL em `domain` (ignorando soft-deletes) — trava no nível do banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('helpdesk_association_rules')) {
            return;
        }

        // 1) Dedupe — mantém empresa 1 (menor id); soft-delete o resto.
        $dupDomains = DB::table('helpdesk_association_rules')
            ->whereNull('deleted_at')
            ->select('domain')
            ->groupBy('domain')
            ->havingRaw('count(*) > 1')
            ->pluck('domain');

        foreach ($dupDomains as $domain) {
            $keepId = DB::table('helpdesk_association_rules')
                ->whereNull('deleted_at')
                ->where('domain', $domain)
                ->orderByRaw('(company_id = 1) desc, id asc')
                ->value('id');

            DB::table('helpdesk_association_rules')
                ->whereNull('deleted_at')
                ->where('domain', $domain)
                ->where('id', '!=', $keepId)
                ->update(['deleted_at' => now()]);
        }

        // 2) Índice único parcial (Postgres) — um domínio ATIVO por tabela, em qualquer empresa.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS helpdesk_association_rules_domain_unique ON helpdesk_association_rules (domain) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS helpdesk_association_rules_domain_unique');
    }
};
