<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cofre de Ambientes (F2) — ACL FINA por usuário × ambiente × operação.
 * Molde de pipeline_view_permissions: 1 linha custom por (user, ambiente); sem linha
 * = default derivado do papel de membro (VaultMember). Admin do cliente-vault gerencia.
 * NÃO substitui o membership (que dá a CHAVE) — só restringe/concede operações.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('env_permissions')) {
            Schema::create('env_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->boolean('can_view')->default(true);
                $table->boolean('can_reveal')->default(false);
                $table->boolean('can_copy')->default(false);
                $table->boolean('can_manage')->default(false); // criar/editar/excluir recursos
                $table->boolean('can_admin')->default(false);  // gerenciar permissões/ambiente
                $table->timestamps();
                $table->unique(['user_id', 'environment_id']);
                $table->index('environment_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('env_permissions');
    }
};
