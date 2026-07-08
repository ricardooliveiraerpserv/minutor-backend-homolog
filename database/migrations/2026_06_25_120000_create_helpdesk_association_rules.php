<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regras de Associação (estilo Movidesk): vinculam um DOMÍNIO de e-mail a uma Organização
 * (cliente) + Perfil de Acesso (+ classificação). Quando uma pessoa é cadastrada por e-mail
 * (abertura de chamado por e-mail / formulário), o domínio identifica o cliente e define o
 * perfil/organização automaticamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_association_rules', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 190);                     // ex.: agroamazonia.com.br
            $table->unsignedBigInteger('customer_id')->nullable();        // Organização (cliente)
            $table->unsignedBigInteger('access_profile_id')->nullable();  // Perfil de Acesso (cliente)
            $table->string('classification', 60)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('domain');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_association_rules');
    }
};
