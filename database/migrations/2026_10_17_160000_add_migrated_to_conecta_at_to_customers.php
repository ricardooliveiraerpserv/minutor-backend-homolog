<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('customers', 'migrated_to_conecta_at')) {
            Schema::table('customers', function (Blueprint $table) {
                // Marca que o cliente foi migrado p/ a Conecta (app.conectaerp.com.br).
                // NULL = cliente normal na ERPSERV. Preenchido = desabilitado aqui, atendido lá.
                $table->timestampTz('migrated_to_conecta_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'migrated_to_conecta_at')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('migrated_to_conecta_at');
            });
        }
    }
};
