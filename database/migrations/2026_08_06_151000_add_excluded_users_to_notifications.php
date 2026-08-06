<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exclusões na notificação: usuários RETIRADOS do envio mesmo que caiam num grupo-alvo
 * (role/vínculo/contratação). Público final = (união dos alvos) − excluded_user_ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('notifications_center', 'excluded_user_ids')) {
            Schema::table('notifications_center', function (Blueprint $t) {
                $t->json('excluded_user_ids')->nullable()->after('target_bonds');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notifications_center', 'excluded_user_ids')) {
            Schema::table('notifications_center', fn (Blueprint $t) => $t->dropColumn('excluded_user_ids'));
        }
    }
};
