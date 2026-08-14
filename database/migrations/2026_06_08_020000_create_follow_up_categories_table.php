<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro de categorias de Follow Up (adicionar/editar/excluir). O follow_ups.category
 * passa a guardar o NOME da categoria escolhida no cadastro.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('follow_up_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $defaults = ['Reunião', 'Projeto', 'Cliente', 'Aprovação', 'Homologação', 'Financeiro', 'Comercial', 'Jurídico', 'Suporte', 'Outro'];
        $rows = [];
        foreach ($defaults as $i => $name) {
            $rows[] = ['name' => $name, 'is_active' => true, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('follow_up_categories')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_categories');
    }
};
