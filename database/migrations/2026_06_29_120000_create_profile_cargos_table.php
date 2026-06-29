<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo Cargo × Perfil. O admin registra o cargo padrão de cada perfil (users.type).
 * Usado como cargo padrão da assinatura/perfil. Sem linha = usa ProfileCargo::DEFAULTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('profile_cargos')) {
            return;
        }
        Schema::create('profile_cargos', function (Blueprint $table) {
            $table->id();
            $table->string('profile', 30)->unique(); // = users.type
            $table->string('cargo', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_cargos');
    }
};
