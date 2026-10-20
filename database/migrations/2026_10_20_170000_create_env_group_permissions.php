<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cofre de Ambientes — ACL por GRUPO de consultores × ambiente × operação.
 * Irmã de env_permissions (que é por usuário). Faltou ser criada: EnvAccess::effectiveFor()
 * consulta env_group_permissions no caminho de NÃO-admin (membro), então sem a tabela
 * qualquer membro não-admin recebia 42P01 → 422 ("Ambiente não encontrado" no front).
 * NÃO substitui o membership (que dá a CHAVE) — só restringe/concede operações.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('env_group_permissions')) {
            Schema::create('env_group_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('consultant_group_id')->constrained('consultant_groups')->cascadeOnDelete();
                $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
                $table->boolean('can_view')->default(true);
                $table->boolean('can_reveal')->default(false);
                $table->boolean('can_copy')->default(false);
                $table->boolean('can_manage')->default(false); // criar/editar/excluir recursos
                $table->boolean('can_admin')->default(false);  // gerenciar permissões/ambiente
                $table->timestamps();
                $table->unique(['consultant_group_id', 'environment_id'], 'env_group_perm_unique');
                $table->index('environment_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('env_group_permissions');
    }
};
