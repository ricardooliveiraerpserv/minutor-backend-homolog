<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Fontes — Frente A. Fila de APROVAÇÃO de custo de IA. Quando o próximo passo estouraria o
 * limite operacional por fonte e approval_required_above_limit=true, o governor cria aqui uma
 * solicitação (pending) EM VEZ de chamar a IA. Aprovador decide: liberar só o próximo passo, elevar o
 * teto desta fonte (≤ max_approved), encerrar parcial ou rejeitar. Tudo auditado em source_doc_action_log.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('source_doc_cost_approvals')) {
            Schema::create('source_doc_cost_approvals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('source_doc_id');
                $table->unsignedBigInteger('version_id')->nullable();
                // pending | approved_step | approved_limit | closed_partial | rejected
                $table->string('status', 24)->default('pending');
                $table->decimal('actual_cost_usd', 8, 4)->default(0);       // acumulado na fonte no disparo
                $table->decimal('authorized_limit_usd', 8, 4)->default(0);  // teto operacional vigente no disparo
                $table->string('next_step', 24)->nullable();                // initial|top_up|critical_rules|deepen
                $table->decimal('estimated_next_usd', 8, 4)->default(0);
                $table->decimal('new_limit_usd', 8, 4)->nullable();         // novo teto quando approved_limit
                $table->string('reason', 200)->nullable();                  // motivo técnico (sanitizado)
                $table->string('completeness_level', 16)->nullable();       // completa|parcial
                $table->jsonb('gaps_json')->nullable();                     // dimensões faltantes
                $table->string('recommendation', 200)->nullable();
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('idempotency_key', 80)->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index(['source_doc_id', 'status']);
            });

            // No máximo 1 aprovação ABERTA por fonte (índice parcial único — padrão do action_log inflight).
            try {
                DB::statement("CREATE UNIQUE INDEX source_doc_cost_approval_open_uq ON source_doc_cost_approvals (source_doc_id) WHERE status = 'pending'");
            } catch (\Throwable) {
                // idempotência defensiva (reexecução / driver sem partial index)
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('source_doc_cost_approvals');
    }
};
