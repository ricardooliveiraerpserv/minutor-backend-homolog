<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — Motivos de DESCARTE do funil de prospecção (leads), distintos dos Motivos
 * de Perda de oportunidade. Cada motivo pode ter `dias_repescagem`: quando um lead
 * é descartado por ele, agenda-se a REPESCAGEM automática (volta ao funil + gera
 * atividade) para daqui a N dias. NULL = não repesca.
 *
 * Multi-empresa: company_id por linha (homolog roda com scoping ligado, que exclui
 * NULL). Seed por empresa existente para o cadastro aparecer nas duas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_discard_reasons')) {
            Schema::create('crm_discard_reasons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->nullable()->after('id')
                    ->constrained('companies')->nullOnDelete();
                $table->string('name', 80);
                $table->integer('ordem')->default(0);
                $table->integer('dias_repescagem')->nullable(); // NULL = nunca repesca
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index('company_id');
            });
        }

        // Seed por empresa (com scoping ligado, linhas sem company_id ficam invisíveis).
        $seed = [
            ['Sem budget',          90],
            ['Sem retorno',         30],
            ['Momento inadequado',  60],
            ['Concorrente',        120],
            ['Sem fit / perfil',  null],
            ['Contato inválido',  null],
            ['Duplicado',         null],
            ['Outro',             null],
        ];
        $companies = DB::table('companies')->pluck('id');
        if ($companies->isEmpty()) {
            $companies = collect([null]); // ambiente sem multi-empresa: uma leva sem company_id
        }
        foreach ($companies as $companyId) {
            $exists = DB::table('crm_discard_reasons')
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->when($companyId === null, fn ($q) => $q->whereNull('company_id'))
                ->exists();
            if ($exists) {
                continue;
            }
            foreach ($seed as $i => [$name, $dias]) {
                DB::table('crm_discard_reasons')->insert([
                    'company_id'      => $companyId,
                    'name'            => $name,
                    'ordem'           => $i + 1,
                    'dias_repescagem' => $dias,
                    'active'          => true,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_discard_reasons');
    }
};
