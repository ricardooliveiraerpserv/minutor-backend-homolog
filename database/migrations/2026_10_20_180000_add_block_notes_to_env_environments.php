<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto livre POR BLOCO no ambiente do Cofre (credenciais, bancos, appservers, VPNs,
 * certificados, links). JSON { bloco: "anotação" }. Não é segredo — texto em claro.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('env_environments', 'block_notes')) {
            Schema::table('env_environments', function (Blueprint $table) {
                $table->jsonb('block_notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('env_environments', 'block_notes')) {
            Schema::table('env_environments', function (Blueprint $table) {
                $table->dropColumn('block_notes');
            });
        }
    }
};
