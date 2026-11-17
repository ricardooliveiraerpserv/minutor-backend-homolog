<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Comunicação com cliente: filtro opcional por Perfil de Acesso do Help Desk (ids de helpdesk_access_profiles). */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('communications', 'hd_profile_ids')) {
            Schema::table('communications', fn (Blueprint $t) => $t->json('hd_profile_ids')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('communications', 'hd_profile_ids')) {
            Schema::table('communications', fn (Blueprint $t) => $t->dropColumn('hd_profile_ids'));
        }
    }
};
