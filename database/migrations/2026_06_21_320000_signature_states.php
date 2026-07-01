<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P-E.2.1 — estados da assinatura no participante (pending/signed/refused/expired/cancelled) + motivo. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_proposal_participants', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_proposal_participants', 'sign_status')) $table->string('sign_status', 12)->default('pending')->after('signed_at');
            if (!Schema::hasColumn('crm_proposal_participants', 'sign_refusal_reason')) $table->text('sign_refusal_reason')->nullable()->after('sign_status');
            if (!Schema::hasColumn('crm_proposal_participants', 'sign_status_at')) $table->timestamp('sign_status_at')->nullable()->after('sign_refusal_reason');
        });
    }

    public function down(): void
    {
        Schema::table('crm_proposal_participants', function (Blueprint $table) {
            foreach (['sign_status', 'sign_refusal_reason', 'sign_status_at'] as $c) {
                if (Schema::hasColumn('crm_proposal_participants', $c)) $table->dropColumn($c);
            }
        });
    }
};
