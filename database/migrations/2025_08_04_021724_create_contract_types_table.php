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
        Schema::create('contract_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            
            // Índices
            $table->index('name');
            $table->index('code');
            $table->index('active');
        });
        
        // Inserir tipos de contrato padrão
        DB::table('contract_types')->insert([
            [
                'name' => 'Banco de Horas Fixo',
                'code' => 'fixed_hours',
                'description' => 'Banco de horas com quantidade fixa definida no contrato',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Banco de Horas Mensal',
                'code' => 'monthly_hours',
                'description' => 'Banco de horas com renovação mensal',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Fechado',
                'code' => 'closed',
                'description' => 'Projeto com escopo fechado e valor fixo',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'On Demand',
                'code' => 'on_demand',
                'description' => 'Trabalho sob demanda sem compromisso de horas',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'SaaS',
                'code' => 'saas',
                'description' => 'Software como Serviço com cobrança recorrente',
                'active' => true,
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
        Schema::dropIfExists('contract_types');
    }
};
