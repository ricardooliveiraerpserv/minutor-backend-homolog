<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override POR USUÁRIO do limite de abas do workspace. NULL = usa o padrão do perfil
 * (SystemSetting tabs.max.<perfil>) ou o default global (5). Teto da aplicação: 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'max_tabs')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedTinyInteger('max_tabs')->nullable()->after('enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'max_tabs')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('max_tabs');
            });
        }
    }
};
