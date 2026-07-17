<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acesso a módulos POR USUÁRIO (foco: perfil cliente).
 *
 * `allowed_modules` = lista JSON dos módulos que o usuário cliente enxerga:
 * ['projetos', 'help_desk']. NULL = vê todos (comportamento legado — não
 * altera os clientes já existentes). Vazio [] = nenhum.
 *
 * O gating geral continua por perfil (users.type); esta coluna é um recorte
 * FINO por usuário, hoje só interpretado para type='cliente'. Ver
 * middleware EnsureClienteModule (enforcement real no backend) + sidebar (FE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'allowed_modules')) {
                $table->json('allowed_modules')->nullable()->after('customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'allowed_modules')) {
                $table->dropColumn('allowed_modules');
            }
        });
    }
};
