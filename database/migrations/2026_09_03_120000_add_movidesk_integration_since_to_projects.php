<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marco de início da integração Movidesk no projeto. Gravado quando a chave é ligada
 * no projeto. Se o admin escolhe "NÃO migrar" ao trocar a chave, recebe a data da troca:
 * a varredura automática só re-roteia apontamentos A PARTIR dessa data — os anteriores
 * ficam no projeto original (trava contra migração automática dos antigos).
 * null = sem trava (comportamento legado: re-roteia tudo).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('projects', 'movidesk_integration_since')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->date('movidesk_integration_since')->nullable()->after('movidesk_integration_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'movidesk_integration_since')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('movidesk_integration_since');
            });
        }
    }
};
