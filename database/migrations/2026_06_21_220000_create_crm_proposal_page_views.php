<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-E.1.1 — Leitura real do PDF por página (deck HTML embutido). Complementa os analytics:
 * não altera fluxo comercial / participantes / revisões.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('crm_proposal_page_views')) return;
        Schema::create('crm_proposal_page_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_proposal_id')->constrained('crm_proposals')->cascadeOnDelete();
            $table->unsignedBigInteger('crm_proposal_share_id')->nullable();
            $table->unsignedBigInteger('crm_proposal_participant_id')->nullable();
            $table->unsignedSmallInteger('page');
            $table->unsignedSmallInteger('total_pages')->nullable();
            $table->timestamp('entered_at');
            $table->timestamp('exited_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->index(['crm_proposal_share_id', 'page']);
            $table->index(['crm_proposal_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_proposal_page_views');
    }
};
