<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licenciamento (REST AdvPL) por ambiente — espelha o padrão do Inventário RPO.
 * Reusa a tabela prosight_rpo_configs (mesmo ambiente/Protheus do cliente) adicionando
 * a URL do endpoint de licença + credencial própria (fallback nas credenciais do RPO)
 * + cache do último resultado. A senha é CIFRADA (cast encrypted no model).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prosight_rpo_configs')) {
            return;
        }
        Schema::table('prosight_rpo_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('prosight_rpo_configs', 'licensing_api_url')) {
                $table->string('licensing_api_url', 500)->nullable()->after('rpo_exclusion_patterns');
            }
            if (! Schema::hasColumn('prosight_rpo_configs', 'licensing_api_user')) {
                $table->string('licensing_api_user', 120)->nullable()->after('licensing_api_url');
            }
            if (! Schema::hasColumn('prosight_rpo_configs', 'licensing_api_password')) {
                $table->text('licensing_api_password')->nullable()->after('licensing_api_user'); // cifrada (cast encrypted)
            }
            if (! Schema::hasColumn('prosight_rpo_configs', 'last_licensing_summary')) {
                $table->json('last_licensing_summary')->nullable();
            }
            if (! Schema::hasColumn('prosight_rpo_configs', 'last_licensing_at')) {
                $table->timestamp('last_licensing_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('prosight_rpo_configs')) {
            return;
        }
        Schema::table('prosight_rpo_configs', function (Blueprint $table) {
            foreach (['licensing_api_url', 'licensing_api_user', 'licensing_api_password', 'last_licensing_summary', 'last_licensing_at'] as $col) {
                if (Schema::hasColumn('prosight_rpo_configs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
