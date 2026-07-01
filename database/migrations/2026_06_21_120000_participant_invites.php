<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P-B.1 — Convites reais: aceite (ip/ua) + reenvio (contagem/última data). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_proposal_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_proposal_participants', 'accepted_ip')) {
                $table->string('accepted_ip', 64)->nullable()->after('accepted_at');
            }
            if (!Schema::hasColumn('crm_proposal_participants', 'accepted_user_agent')) {
                $table->text('accepted_user_agent')->nullable()->after('accepted_ip');
            }
            if (!Schema::hasColumn('crm_proposal_participants', 'last_invite_at')) {
                $table->timestamp('last_invite_at')->nullable()->after('invited_at');
            }
            if (!Schema::hasColumn('crm_proposal_participants', 'invite_count')) {
                $table->unsignedInteger('invite_count')->default(0)->after('last_invite_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_proposal_participants', function (Blueprint $table) {
            foreach (['accepted_ip', 'accepted_user_agent', 'last_invite_at', 'invite_count'] as $c) {
                if (Schema::hasColumn('crm_proposal_participants', $c)) $table->dropColumn($c);
            }
        });
    }
};
