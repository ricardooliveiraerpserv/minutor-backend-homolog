<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planejamento Anual (ciclo de OKR). Cada ano tem seu próprio conjunto de
 * objetivos e metas. company_id prepara o isolamento multi-empresa (inerte
 * enquanto a flag multiempresa.scoping_enabled estiver desligada).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('okr_cycles')) {
            Schema::create('okr_cycles', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->integer('year')->comment('Ano do planejamento (ex.: 2027)');
                $t->string('name', 200)->comment('Nome do planejamento');
                $t->text('description')->nullable();
                $t->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
                $t->date('start_date')->nullable();
                $t->date('end_date')->nullable();
                // rascunho | em_planejamento | aprovado | em_execucao | encerrado
                $t->string('status', 20)->default('rascunho');
                // Limiares do semáforo e outras configs do ciclo (ex.: {"verde":95,"amarelo":80}).
                $t->json('settings')->nullable();
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('approved_at')->nullable();
                $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('closed_at')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();

                $t->unique(['company_id', 'year']);
                $t->index('year');
                $t->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_cycles');
    }
};
