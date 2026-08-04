<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Cadastro configurável de Segmentos de mercado (usado no perfil CRM da empresa). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_segments')) {
            Schema::create('crm_segments', function (Blueprint $t) {
                $t->id();
                $t->string('name')->unique();
                $t->integer('ordem')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (DB::table('crm_segments')->count() === 0) {
            $segs = ['Agronegócio', 'Construção', 'Distribuição', 'Educacional', 'Financeiras', 'Hotelaria', 'Industria / Manufatura', 'Juridico', 'Logistica', 'Saúde', 'Serviços', 'Varejo'];
            foreach ($segs as $i => $name) {
                DB::table('crm_segments')->insert(['name' => $name, 'ordem' => $i + 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_segments');
    }
};
