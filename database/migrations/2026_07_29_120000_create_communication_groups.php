<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos de distribuição da Central de Comunicação — versão estruturada em BLOCOS
 * por cliente. Cada bloco pertence a um cliente e agrega destinatários que vêm dos
 * e-mails já cadastrados (usuários type=cliente) OU manuais (sem virar contato).
 * Coexiste com distribution_lists (listas planas, mantidas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('communication_groups')) {
            Schema::create('communication_groups', function (Blueprint $t) {
                $t->id();
                $t->string('nome', 200);
                $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('communication_group_blocks')) {
            Schema::create('communication_group_blocks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('group_id')->constrained('communication_groups')->cascadeOnDelete();
                $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $t->string('label', 200)->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->timestamps();
                $t->index('group_id');
            });
        }

        if (!Schema::hasTable('communication_group_recipients')) {
            Schema::create('communication_group_recipients', function (Blueprint $t) {
                $t->id();
                $t->foreignId('block_id')->constrained('communication_group_blocks')->cascadeOnDelete();
                // user_id preenchido quando o e-mail vem de um usuário cliente já cadastrado.
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('email', 255);
                $t->string('name', 200)->nullable();
                $t->string('kind', 20)->default('manual'); // cadastrado | manual
                $t->timestamps();
                $t->unique(['block_id', 'email']);
                $t->index('block_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_group_recipients');
        Schema::dropIfExists('communication_group_blocks');
        Schema::dropIfExists('communication_groups');
    }
};
