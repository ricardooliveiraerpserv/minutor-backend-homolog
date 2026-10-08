<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes convidados a ver uma REQUISIÇÃO (contract_request) no pipeline.
 * Paralelo a project_client_viewers, mas no nível da requisição (pré-projeto).
 * Ao gerar o projeto, os convidados são herdados para project_client_viewers.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('contract_request_viewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_request_id')->constrained('contract_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['contract_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_request_viewers');
    }
};
