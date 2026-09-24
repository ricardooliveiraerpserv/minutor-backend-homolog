<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CRM — origens de lead CONFIGURÁVEIS (não fixadas em código). Cadastro editável
 * usado na captação de leads e nos indicadores por origem do dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_lead_sources')) {
            Schema::create('crm_lead_sources', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80)->unique();
                $table->integer('ordem')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });

            // Seed inicial (sugestão) — editável pela tela de cadastro de origens.
            $defaults = ['Site', 'Google', 'Instagram', 'Facebook', 'LinkedIn', 'WhatsApp',
                'Evento', 'Indicação', 'Parceiro', 'Cliente', 'Prospecção Ativa', 'Outros'];
            foreach ($defaults as $i => $name) {
                DB::table('crm_lead_sources')->insert([
                    'name' => $name, 'ordem' => $i + 1, 'active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_sources');
    }
};
