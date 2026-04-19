<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adiciona suporte ao status liberado_para_testes nos projetos.
// O campo status já é string simples (sem ENUM restrito), então nenhuma
// alteração estrutural é necessária — apenas documenta a intenção.
return new class extends Migration
{
    public function up(): void
    {
        // O campo project.status já é VARCHAR sem ENUM no PostgreSQL,
        // então o novo valor 'liberado_para_testes' é aceito imediatamente.
        // Esta migration serve como marcador de versão.
    }

    public function down(): void {}
};
