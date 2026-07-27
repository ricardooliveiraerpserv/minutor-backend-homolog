<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACL de GRUPO do Cofre de Ambientes (herança automática): a permissão é do Grupo de
 * Consultores × ambiente; qualquer membro do grupo herda — inclusive quem entrar depois.
 * Aditivo: EnvAccess resolve custom-usuário > união dos grupos > default do papel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('env_group_permissions')) {
            return;
        }
        Schema::create('env_group_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_group_id')->constrained('consultant_groups')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained('env_environments')->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_reveal')->default(false);
            $table->boolean('can_copy')->default(false);
            $table->boolean('can_manage')->default(false);
            $table->boolean('can_admin')->default(false);
            $table->timestamps();
            $table->unique(['consultant_group_id', 'environment_id']);
            $table->index('environment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('env_group_permissions');
    }
};
