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
        Schema::create('hour_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->integer('contributed_hours')->comment('Quantidade de horas aportadas');
            $table->decimal('hourly_rate', 8, 2)->comment('Valor da hora neste aporte');
            $table->text('description')->nullable()->comment('Descrição/motivo do aporte');
            $table->foreignId('contributed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('contributed_at')->useCurrent()->comment('Data do aporte');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('project_id');
            $table->index('contributed_at');
        });
        
        // Migrar aportes legados (hour_contribution > 0)
        DB::statement("
            INSERT INTO hour_contributions 
                (project_id, contributed_hours, hourly_rate, description, contributed_at, created_at, updated_at)
            SELECT 
                id,
                hour_contribution,
                hourly_rate,
                'Aporte inicial migrado automaticamente',
                COALESCE(start_date, created_at),
                created_at,
                updated_at
            FROM projects
            WHERE hour_contribution > 0
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hour_contributions');
    }
};
