<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 3 — company_id na Central de Notificações (notifications_center), por empresa.
 * Backfill → ERPSERV. (notification_reads/polls são por usuário/filhos → herdam.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications_center') && !Schema::hasColumn('notifications_center', 'company_id')) {
            Schema::table('notifications_center', function (Blueprint $t) {
                $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $t->index('company_id');
            });
            $erpservId = DB::table('companies')->where('slug', 'erpserv')->value('id');
            if ($erpservId) {
                DB::table('notifications_center')->whereNull('company_id')->update(['company_id' => $erpservId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications_center') && Schema::hasColumn('notifications_center', 'company_id')) {
            Schema::table('notifications_center', function (Blueprint $t) {
                $t->dropConstrainedForeignId('company_id');
            });
        }
    }
};
