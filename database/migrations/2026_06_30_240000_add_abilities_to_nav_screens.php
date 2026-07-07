<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permissões POR AÇÃO na tela (rotina). Estrutura:
 *   abilities = { "<action>": { profiles: [], users: [], deny_users: [] }, ... }
 * - profiles  → perfis que PODEM a ação (allow-list; desmarcar = retirar do perfil)
 * - users     → usuários liberados além do perfil (override +)
 * - deny_users→ usuários explicitamente removidos da ação (override −, "o inverso")
 *
 * Camada de CONFIGURAÇÃO (governança). Enforcement é progressivo por rotina (Apontamentos já
 * usa AccessControl como referência).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('nav_screens', 'abilities')) {
            Schema::table('nav_screens', fn (Blueprint $t) => $t->json('abilities')->nullable()->after('users'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nav_screens', 'abilities')) {
            Schema::table('nav_screens', fn (Blueprint $t) => $t->dropColumn('abilities'));
        }
    }
};
