<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Governança de Revisões — vincula cada thread à VERSÃO da proposta em que foi aberta/resolvida.
 * Permite rastrear evolução da negociação e retrabalho, sem criar workflow/BPM.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_proposal_review_threads', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_proposal_review_threads', 'opened_revision_number')) {
                $table->unsignedSmallInteger('opened_revision_number')->nullable()->after('status');
            }
            if (!Schema::hasColumn('crm_proposal_review_threads', 'resolved_revision_number')) {
                $table->unsignedSmallInteger('resolved_revision_number')->nullable()->after('opened_revision_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_proposal_review_threads', function (Blueprint $table) {
            foreach (['opened_revision_number', 'resolved_revision_number'] as $c) {
                if (Schema::hasColumn('crm_proposal_review_threads', $c)) $table->dropColumn($c);
            }
        });
    }
};
