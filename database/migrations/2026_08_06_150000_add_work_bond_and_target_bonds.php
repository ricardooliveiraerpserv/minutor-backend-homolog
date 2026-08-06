<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo do consultor (Fixo | Free Lance) — distinto do consultant_type (remuneração:
 * horista/banco/fixo). Usado também p/ segmentar o público das notificações (target_bonds).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'work_bond')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('work_bond', 20)->nullable()->after('consultant_type'); // fixo | freelance
            });
        }
        if (!Schema::hasColumn('notifications_center', 'target_bonds')) {
            Schema::table('notifications_center', function (Blueprint $t) {
                $t->json('target_bonds')->nullable()->after('target_contract_types');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'work_bond')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('work_bond'));
        }
        if (Schema::hasColumn('notifications_center', 'target_bonds')) {
            Schema::table('notifications_center', fn (Blueprint $t) => $t->dropColumn('target_bonds'));
        }
    }
};
