<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `visible`: notificação aparece (ou não) na Central in-app. `show_results`: na enquete, define
 * se o usuário acompanha o resultado depois de votar (se não, só vota e a enquete some pra ele).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            $table->boolean('visible')->default(true)->after('send_email');
        });
        Schema::table('notification_polls', function (Blueprint $table) {
            $table->boolean('show_results')->default(true)->after('allow_change_vote');
        });
    }

    public function down(): void
    {
        Schema::table('notifications_center', fn (Blueprint $table) => $table->dropColumn('visible'));
        Schema::table('notification_polls', fn (Blueprint $table) => $table->dropColumn('show_results'));
    }
};
