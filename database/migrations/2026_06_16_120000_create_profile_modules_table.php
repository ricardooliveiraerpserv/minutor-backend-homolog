<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro de Perfil → Módulos de navegação (Serviços / Administrativo).
 * Camada SEPARADA das permissões: define apenas QUAIS módulos da nova navegação
 * cada perfil (users.type) enxerga. Não altera PermissionService nem user.type.
 * Sem linha = usa os defaults do Model (ProfileModule::DEFAULTS). Cliente fica fora.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_modules')) {
            return;
        }
        Schema::create('profile_modules', function (Blueprint $table) {
            $table->id();
            $table->string('profile', 30)->unique(); // = users.type
            $table->json('modules');                  // ex.: ["servicos","administrativo"]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_modules');
    }
};
