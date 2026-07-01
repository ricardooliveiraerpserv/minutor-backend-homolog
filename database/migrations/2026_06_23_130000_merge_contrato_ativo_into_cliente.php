<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // "contrato_ativo" foi unificado em "cliente" (mesmo conceito comercial).
    public function up(): void
    {
        DB::statement("UPDATE customers SET crm_status = 'cliente' WHERE crm_status = 'contrato_ativo'");
    }

    public function down(): void
    {
        // Irreversível com segurança (não há como distinguir quem era contrato_ativo). No-op.
    }
};
