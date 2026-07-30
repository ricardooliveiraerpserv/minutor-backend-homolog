<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Log de exclusão de requisições/contratos no pipeline (Demandas e Projetos).
// Auditoria: quem excluiu, quando, o quê (snapshot) e por quê (motivo).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contract_deletion_logs')) return;

        Schema::create('contract_deletion_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->string('contract_name')->nullable();     // nome/título da requisição
            $table->string('customer_name')->nullable();
            $table->string('kanban_status')->nullable();      // fase (coluna do pipeline)
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deleted_by_name')->nullable();    // snapshot do nome (caso o user seja removido)
            $table->text('reason')->nullable();               // motivo informado na exclusão
            $table->json('snapshot')->nullable();             // cópia completa do contrato p/ auditoria
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_deletion_logs');
    }
};
