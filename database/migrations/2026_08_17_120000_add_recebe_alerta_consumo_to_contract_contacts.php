<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('contract_contacts', 'recebe_alerta_consumo')) {
            Schema::table('contract_contacts', function (Blueprint $table) {
                $table->boolean('recebe_alerta_consumo')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contract_contacts', 'recebe_alerta_consumo')) {
            Schema::table('contract_contacts', function (Blueprint $table) {
                $table->dropColumn('recebe_alerta_consumo');
            });
        }
    }
};
