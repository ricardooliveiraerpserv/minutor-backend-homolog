<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('crm_pipelines', 'tipos_empresa')) {
            Schema::table('crm_pipelines', function (Blueprint $table) {
                // Tipos de empresa (crm_status) que este pipeline trabalha. NULL/[] = todos.
                $table->json('tipos_empresa')->nullable()->after('tipo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_pipelines', 'tipos_empresa')) {
            Schema::table('crm_pipelines', fn (Blueprint $t) => $t->dropColumn('tipos_empresa'));
        }
    }
};
