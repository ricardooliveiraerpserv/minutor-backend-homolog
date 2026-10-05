<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connector-4.1 — fundação de OPERAÇÕES destrutivas/controladas (nesta fase SÓ 'start'). Classe de
 * segurança SEPARADA do C-3 (connector_commands): NÃO herda retry/at-least-once. Propriedades travadas:
 *  - execution_id nasce COM a operação e é IMUTÁVEL até o terminal (uma intenção = um execution_id).
 *  - at-most-once no EFEITO: sem requeue/reclaim; 'expired' só p/ dispatchable NUNCA reivindicado.
 *  - a partir de 'claimed', timeout/perda/dúvida (mesmo sem ACK de execution_committed) → 'indeterminate'.
 *  - autoridade final do desfecho = C-2 observado (pré-imagem down → pós-imagem up com process_instance_id).
 *  - maker-checker (requested_by ≠ approved_by); 1 operação viva por appserver_ref E por environment_id.
 * Schema nasce pronto p/ start/stop/restart, mas a allowlist operacional inicial é SÓ 'start' (config).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('connector_operations')) {
            return;
        }
        Schema::create('connector_operations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('environment_id');
            $t->uuid('appserver_ref');                       // alvo específico
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('op_type', 12);                       // start (stop/restart bloqueados nesta fase)
            $t->string('status', 24)->default('requested');
            $t->uuid('execution_id');                        // IMUTÁVEL, 1 por operação, vida toda
            $t->unsignedBigInteger('requested_by');
            $t->string('reason', 300);
            $t->string('approval_state', 12)->default('pending'); // not_required|pending|approved|rejected
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('maintenance_window', 40)->nullable();
            $t->string('precondition_kind', 8)->nullable();  // 'down' p/ start
            $t->jsonb('precondition_snapshot')->nullable();  // pré-imagem C-2
            $t->timestamp('dispatchable_at')->nullable();
            $t->timestamp('transport_lease_expires_at')->nullable(); // rege SÓ até o claim
            $t->uuid('claimed_by_agent_id')->nullable();
            $t->timestamp('claimed_at')->nullable();         // fronteira do "provavelmente nada aconteceu"
            $t->timestamp('execution_committed_at')->nullable();
            $t->timestamp('executing_at')->nullable();
            $t->timestamp('operational_deadline_at')->nullable(); // timeout OPERACIONAL (≠ transport lease)
            $t->string('agent_result', 10)->nullable();      // ok|fail|unknown
            $t->timestamp('agent_result_at')->nullable();
            $t->string('agent_result_detail', 200)->nullable();
            $t->string('agent_result_phase', 12)->nullable(); // pre_effect|post_effect
            $t->string('reconciliation_state', 16)->default('none'); // none|pending|success|noop|contradicted|unresolved
            $t->jsonb('postimage_snapshot')->nullable();
            $t->timestamp('reconciled_at')->nullable();
            $t->string('outcome_authority', 8)->nullable();  // agent|observed|human
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamps();

            $t->index(['environment_id', 'status']);
            $t->index(['status', 'operational_deadline_at']); // reaper → indeterminate (NUNCA requeue)
            $t->index(['appserver_ref', 'created_at']);
            $t->unique('execution_id');
        });

        // maker-checker no banco.
        DB::statement('ALTER TABLE connector_operations ADD CONSTRAINT connector_operations_maker_ne_checker
            CHECK (approved_by IS NULL OR approved_by <> requested_by)');
        // Concorrência: 1 operação VIVA por appserver_ref E 1 por environment_id (v1 conservador).
        $alive = "status NOT IN ('failed','expired','canceled','rejected','reconciled_success','reconciled_noop')";
        DB::statement("CREATE UNIQUE INDEX connector_operations_one_live_per_appserver
            ON connector_operations (appserver_ref) WHERE {$alive}");
        DB::statement("CREATE UNIQUE INDEX connector_operations_one_live_per_environment
            ON connector_operations (environment_id) WHERE {$alive}");
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_operations');
    }
};
