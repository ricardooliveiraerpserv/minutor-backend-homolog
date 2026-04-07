<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
            
            // Índices
            $table->index('name');
        });
        
        // Inserir tipos de serviço padrão
        DB::table('service_types')->insert([
            [
                'name' => 'Sustentação',
                'description' => 'Serviços de manutenção e suporte contínuo',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Projeto',
                'description' => 'Desenvolvimento de projetos específicos',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Investimento Comercial',
                'description' => 'Projetos de investimento e expansão comercial',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};
