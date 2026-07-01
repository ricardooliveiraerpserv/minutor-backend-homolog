<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-C.1 — Portal HTML Estruturado.
 *  - crm_proposal_sections: seções navegáveis da proposta (entidade independente, id estável p/ fases futuras).
 *  - crm_proposal_section_views: tracking de permanência por seção (section_entered/exited + duration).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('crm_proposal_sections')) {
            Schema::create('crm_proposal_sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('crm_proposal_id')->constrained('crm_proposals')->cascadeOnDelete();
                $table->string('section_key', 40);
                $table->string('title', 120);
                $table->unsignedSmallInteger('order')->default(0);
                $table->longText('content')->nullable();   // HTML renderizado (snapshot derivado do conteudo/calc)
                $table->boolean('visible')->default(true);
                $table->timestamps();
                $table->unique(['crm_proposal_id', 'section_key']);
            });
        }

        if (!Schema::hasTable('crm_proposal_section_views')) {
            Schema::create('crm_proposal_section_views', function (Blueprint $table) {
                $table->id();
                $table->foreignId('crm_proposal_id')->constrained('crm_proposals')->cascadeOnDelete();
                $table->unsignedBigInteger('crm_proposal_share_id')->nullable();
                $table->unsignedBigInteger('crm_proposal_participant_id')->nullable();
                $table->string('section_key', 40);
                $table->timestamp('entered_at');
                $table->timestamp('exited_at')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->timestamps();
                $table->index(['crm_proposal_share_id', 'section_key']);
                $table->index(['crm_proposal_id', 'section_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_proposal_section_views');
        Schema::dropIfExists('crm_proposal_sections');
    }
};
