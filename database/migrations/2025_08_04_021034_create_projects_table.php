<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            
            // Relacionamento com customer
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            
            // Valores e horas
            $table->decimal('project_value', 12, 2)->nullable()->comment('Valor do projeto (fixo ou calculado)');
            $table->decimal('hourly_rate', 8, 2)->nullable()->comment('Valor da hora');
            $table->integer('sold_hours')->nullable()->comment('Horas vendidas');
            $table->integer('hour_contribution')->nullable()->comment('Aporte de horas');
            $table->decimal('additional_hourly_rate', 8, 2)->nullable()->comment('Valor de horas adicionais');
            
            // Datas
            $table->date('start_date')->nullable();
            
            // Política de despesas
            $table->text('expense_policy')->nullable();
            
            // Relacionamento com tipo de serviço
            $table->foreignId('service_type_id')->constrained('service_types');
            
            // Enums
            $table->enum('contract_type', [
                'fixed_hours',     // Banco de Horas Fixo
                'monthly_hours',   // Banco de Horas Mensal
                'closed',          // Fechado
                'on_demand',       // On Demand
                'saas'             // SaaS
            ]);
            
            $table->enum('status', [
                'awaiting_start',  // Aguardando início
                'started',         // Iniciado
                'paused',          // Pausado
                'cancelled',       // Cancelado
                'finished'         // Encerrado
            ])->default('awaiting_start');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para melhor performance
            $table->index('name');
            $table->index('code');
            $table->index('customer_id');
            $table->index('service_type_id');
            $table->index('status');
            $table->index('contract_type');
            $table->index('start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
