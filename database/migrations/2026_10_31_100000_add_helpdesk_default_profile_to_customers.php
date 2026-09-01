<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil de acesso do Help Desk PADRÃO por CLIENTE. Ao pré-cadastrar uma pessoa-cliente (sem regra
 * de associação), aplica-se este perfil — substitui o antigo "default global" (is_default por tipo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'helpdesk_default_access_profile_id')) {
                $table->unsignedBigInteger('helpdesk_default_access_profile_id')->nullable();
                $table->foreign('helpdesk_default_access_profile_id')
                    ->references('id')->on('helpdesk_access_profiles')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'helpdesk_default_access_profile_id')) {
                $table->dropForeign(['helpdesk_default_access_profile_id']);
                $table->dropColumn('helpdesk_default_access_profile_id');
            }
        });
    }
};
