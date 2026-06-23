<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — tarefas comerciais ("Próxima Ação"). Conceito enxuto: data, responsável, prioridade,
 * conclusão. "Próxima ação" = próxima task aberta; "Última interação" = max(concluida_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_tasks')) {
            return;
        }
        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('crm_opportunities')->cascadeOnDelete();
            $table->string('tipo', 20);                 // ligacao|whatsapp|email|reuniao|visita|demo
            $table->string('titulo', 180)->nullable();
            $table->timestamp('data')->nullable();      // quando fazer
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('prioridade', 10)->default('media'); // baixa|media|alta
            $table->timestamp('concluida_at')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['opportunity_id', 'concluida_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_tasks');
    }
};
