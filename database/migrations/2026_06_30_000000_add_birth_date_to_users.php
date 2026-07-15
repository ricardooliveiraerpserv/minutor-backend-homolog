<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Coluna birth_date usada pelo cadastro/perfil (assinatura). Na Replica ela vem junto da feature
// de mensagens de aniversário; aqui subimos só a coluna (idempotente — a feature de aniversário,
// se subir depois, também tem o guard hasColumn e não conflita).
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'birth_date')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('birth_date')->nullable()->after('matricula');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'birth_date')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('birth_date'));
        }
    }
};
