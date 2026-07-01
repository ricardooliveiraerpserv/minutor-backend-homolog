<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM Item 1 (Opção A) — permite empresa sem CNPJ nas fases Lead/Prospect.
 * `customers.cgc` deixa de ser NOT NULL; obrigatoriedade passa a ser por regra
 * (crm_status ∈ {cliente, contrato_ativo} → CNPJ obrigatório), validada na aplicação.
 *
 * O índice único parcial `customers_cgc_unique_not_deleted (cgc, (deleted_at IS NULL))`
 * é mantido: no Postgres NULLs são distintos, então vários leads sem CNPJ coexistem.
 * Migra placeholders 'LEAD-*' (estratégia antiga do MVP) de volta para NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customers ALTER COLUMN cgc DROP NOT NULL');
        // Limpa placeholders do MVP (leads/prospects sem CNPJ real).
        DB::table('customers')->where('cgc', 'like', 'LEAD-%')->update(['cgc' => null]);
    }

    public function down(): void
    {
        // Reverte placeholders para um valor não-nulo antes de reimpor NOT NULL.
        DB::table('customers')->whereNull('cgc')
            ->update(['cgc' => DB::raw("'LEAD-' || upper(substr(md5(random()::text), 1, 8))")]);
        DB::statement('ALTER TABLE customers ALTER COLUMN cgc SET NOT NULL');
    }
};
