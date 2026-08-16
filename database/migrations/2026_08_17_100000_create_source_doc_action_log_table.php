<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Fontes — C3. Trilha de auditoria + estado de execução das AÇÕES operacionais
 * (validar/reprocessar/download/…). Guarda SOMENTE metadata operacional — NUNCA código, prompts,
 * tokens, secrets, payloads íntegros ou conteúdo de arquivo (refinamento #2).
 *
 * Também é a fonte do estado de execução do reprocessamento (a linha mais recente de reprocess)
 * e da IDEMPOTÊNCIA: idempotency_key = doc + blob + layer + prompt_version/modelo. Índice parcial
 * único garante que não haja duas execuções equivalentes em andamento ao mesmo tempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('source_doc_action_log')) {
            return;
        }
        Schema::create('source_doc_action_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_doc_id');
            $table->unsignedBigInteger('version_id')->nullable();
            $table->string('action', 30);                 // validate|reprocess|download|view_git|compare
            $table->string('layer', 20)->nullable();      // reprocess: deterministic|semantic|both
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('status', 20)->default('ok');  // queued|running|ok|failed|skipped
            $table->string('reason', 200)->nullable();    // motivo curto (sanitizado)
            $table->string('idempotency_key', 120)->nullable();
            $table->decimal('cost_usd', 8, 4)->nullable(); // custo REAL da IA (só reprocess semântico)
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('params')->nullable();            // metadata SANITIZADA (ver SourceDocActionLog::sanitize)
            $table->timestamps();

            $table->foreign('source_doc_id')->references('id')->on('source_docs')->cascadeOnDelete();
            $table->index(['source_doc_id', 'action']);
            $table->index(['source_doc_id', 'created_at']);
        });

        // Uma execução equivalente por vez (idempotência anti-concorrência): parcial em andamento.
        DB::statement("CREATE UNIQUE INDEX source_doc_action_inflight_uq
            ON source_doc_action_log (idempotency_key)
            WHERE idempotency_key IS NOT NULL AND status IN ('queued','running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_action_log');
    }
};
