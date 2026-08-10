<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'auto_inactivated_at')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            // Marca que o usuário foi inativado AUTOMATICAMENTE pela regra dos 180 dias sem apontamento.
            // Null = ativo ou inativado manualmente (o job só reativa quem ELE mesmo inativou).
            $table->timestamp('auto_inactivated_at')->nullable()->after('enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'auto_inactivated_at')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auto_inactivated_at');
        });
    }
};
