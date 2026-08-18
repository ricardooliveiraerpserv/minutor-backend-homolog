<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Novo tipo de serviço "Alocação" (código `alocacao`). Idempotente.
 * Fica FORA do board "Demandas e Projetos" (filtro no FE), mas aparece no
 * Kanban de Contratos com coordenador; faturamento on demand/mensal/fixo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('service_types')->where('code', 'alocacao')->exists()) {
            DB::table('service_types')->insert([
                'name'       => 'Alocação',
                'code'       => 'alocacao',
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('service_types')->where('code', 'alocacao')->delete();
    }
};
