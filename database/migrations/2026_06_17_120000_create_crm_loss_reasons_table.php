<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/** CRM Item 2 — motivos de perda CONFIGURÁVEIS (Cadastros CRM › Motivos de Perda). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_loss_reasons')) {
            Schema::create('crm_loss_reasons', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80)->unique();
                $table->integer('ordem')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
            $seed = ['Preço', 'Concorrente', 'Sem orçamento', 'Projeto cancelado', 'Sem retorno', 'Mudança de prioridade', 'Prazo', 'Outro'];
            foreach ($seed as $i => $name) {
                DB::table('crm_loss_reasons')->insert(['name' => $name, 'ordem' => $i + 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_loss_reasons');
    }
};
