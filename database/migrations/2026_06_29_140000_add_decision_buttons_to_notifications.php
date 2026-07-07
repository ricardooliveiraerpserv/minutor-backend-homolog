<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Botões de decisão personalizados na notificação:
 *  - notifications_center.actions = JSON com os nomes dos botões definidos pelo admin (ex.: ["Confirmo presença","Não vou"])
 *  - notification_reads.response_action = qual botão o usuário clicou (resultado / log)
 * Reaproveita notification_reads (viewed_at = visualização; ack_at = respondeu) — sem tabela nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications_center', 'actions')) {
                $table->json('actions')->nullable()->after('cta_url'); // ["Confirmo presença","Não vou"]
            }
        });
        Schema::table('notification_reads', function (Blueprint $table) {
            if (!Schema::hasColumn('notification_reads', 'response_action')) {
                $table->string('response_action', 80)->nullable()->after('acked_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications_center', function (Blueprint $table) {
            if (Schema::hasColumn('notifications_center', 'actions')) $table->dropColumn('actions');
        });
        Schema::table('notification_reads', function (Blueprint $table) {
            if (Schema::hasColumn('notification_reads', 'response_action')) $table->dropColumn('response_action');
        });
    }
};
