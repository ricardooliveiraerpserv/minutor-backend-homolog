<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de usuários autorizados a indicar acompanhante do tipo "Outro" (além da família).
 * Quem não estiver aqui só pode Cônjuge/Filho(a). Mesmo padrão de target_users/excluded_user_ids (json).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications_center', 'guest_other_user_ids')) {
                $table->json('guest_other_user_ids')->nullable()->after('allow_guests');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            if (Schema::hasColumn('notifications_center', 'guest_other_user_ids')) {
                $table->dropColumn('guest_other_user_ids');
            }
        });
    }
};
