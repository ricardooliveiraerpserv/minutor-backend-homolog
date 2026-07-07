<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurador de navegação: módulos definidos pelo admin + quais "itens de menu"
 * (grupos do catálogo, definidos no FE) cada módulo contém, e em que ordem.
 * Camada de NAVEGAÇÃO apenas (não mexe em permissões).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nav_modules')) {
            return;
        }
        Schema::create('nav_modules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();              // slug do módulo (ex.: administrativo)
            $table->string('label');                      // rótulo exibido
            $table->string('icon')->default('LayoutGrid'); // nome do ícone lucide
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_system')->default(false); // sistema = não pode ser excluído
            $table->boolean('active')->default(true);
            $table->json('items')->nullable();            // lista ordenada de keys do catálogo (FE)
            $table->timestamps();
        });

        // Seed inicial: espelha o agrupamento hardcoded atual (Administrativo primeiro).
        $now = now();
        DB::table('nav_modules')->insert([
            [
                'key' => 'administrativo', 'label' => 'Administrativo', 'icon' => 'BarChart2',
                'sort_order' => 1, 'is_system' => true, 'active' => true,
                'items' => json_encode(['gestao_contratual', 'financeiro', 'relatorios', 'cadastros', 'comunicacao', 'visao_externa', 'sistema']),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'servicos', 'label' => 'Serviços', 'icon' => 'Wrench',
                'sort_order' => 2, 'is_system' => true, 'active' => true,
                'items' => json_encode(['projetos', 'sustentacao', 'operacao']),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'configurador', 'label' => 'Configurador', 'icon' => 'SlidersHorizontal',
                'sort_order' => 3, 'is_system' => true, 'active' => true,
                'items' => json_encode(['configurador']),
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_modules');
    }
};
