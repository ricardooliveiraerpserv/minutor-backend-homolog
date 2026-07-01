<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** V1.2 — feedback do diagnóstico automático (valida utilidade antes da P-E.2). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('crm_proposal_diagnostic_feedback')) return;
        Schema::create('crm_proposal_diagnostic_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_proposal_id')->constrained('crm_proposals')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('resposta', 12);            // sim | parcial | nao
            $table->text('comentario')->nullable();
            $table->timestamps();
            $table->index('crm_proposal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_proposal_diagnostic_feedback');
    }
};
