<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil de assinatura por e-mail (P-E.2.4): guarda nome/CPF/cargo/traço da 1ª assinatura para
 * reuso — nas próximas, basta o e-mail validar (sem redigitar dados nem desenhar de novo).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('crm_signature_profiles')) return;
        Schema::create('crm_signature_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name', 160);
            $table->string('cpf', 20)->nullable();
            $table->string('cargo', 120)->nullable();
            $table->longText('image')->nullable(); // data-URL do traço
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_signature_profiles');
    }
};
