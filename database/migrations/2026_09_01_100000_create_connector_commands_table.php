<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha Conector — Connector-3 (orquestração de comandos ASSÍNCRONOS, não destrutivos).
 * Fila = a própria tabela; o AGENTE é o worker (long-poll outbound-only). Comando persistido ANTES
 * da execução; execução NUNCA é síncrona no request HTTP. Nesta fase o ÚNICO command_type é
 * collect_inventory_now (dispara o pipeline C-2 já homologado; sem lógica de inventário nova).
 *
 * Semântica travada (ajustes aprovados):
 *  - attempts INCREMENTA atomicamente NO CLAIM; max_attempts=2 (um único retry controlado).
 *  - entrega AT-LEAST-ONCE (não exactly-once): NÃO transportar p/ comandos destrutivos do C-4/C-5.
 *  - claim exclusivo com LEASE (claim_expires_at) + claim_token liga o RESULTADO a UM claim.
 *  - correlação FORTE comando→inventário via connector_commands.inventory_applied_at (setado pelo
 *    inventário com trigger.command_id do MESMO ambiente/agente); nunca por ordem temporal.
 *  - cancelamento simples: queued→canceled; claimed/running→409; terminais imutáveis (SEM cancel_state).
 * Nenhum secret/path/INI/bytes de RPO entra aqui (params/result_meta = allowlist por tipo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('connector_commands')) {
            return;
        }
        Schema::create('connector_commands', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('environment_id');            // AUTORIDADE de escopo (nunca do payload do agente)
            $t->unsignedBigInteger('customer_id')->nullable();   // denormalizado p/ escopo/auditoria
            $t->string('command_type', 40);                      // ALLOWLIST — nesta fase só collect_inventory_now
            $t->jsonb('params')->nullable();                     // sanitizado/allowlist por tipo (vazio p/ collect)
            $t->string('status', 16)->default('queued');         // queued|claimed|running|succeeded|failed|expired|canceled
            $t->string('idempotency_key', 80)->nullable();       // coalescing de duplicados / debounce
            $t->unsignedSmallInteger('attempts')->default(0);    // incrementa NO CLAIM
            $t->unsignedSmallInteger('max_attempts')->default(2);
            $t->unsignedBigInteger('requested_by')->nullable();  // quem AUTORIZOU
            $t->uuid('claimed_by_agent_id')->nullable();
            $t->string('claim_token', 64)->nullable();           // liga o RESULTADO a UM claim (anti-resultado-atrasado)
            $t->timestamp('claim_expires_at')->nullable();       // LEASE do claim
            $t->timestamp('available_at')->nullable();           // backoff de retry (não elegível antes)
            $t->timestamp('expires_at');                         // TTL DURO (queued sem claim expira)
            $t->timestamp('inventory_applied_at')->nullable();   // CORRELAÇÃO forte: inventário aplicado c/ este command_id
            $t->string('result_outcome', 12)->nullable();        // ok|fail (reportado pelo agente)
            $t->string('result_detail', 200)->nullable();        // sanitizado
            $t->jsonb('result_meta')->nullable();                // sanitizado: {duration_ms, observed_at}
            $t->timestamp('enqueued_at')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();

            $t->index(['environment_id', 'status']);
            $t->index(['status', 'claim_expires_at']);
            $t->index(['environment_id', 'created_at']);
        });

        // Dedup de comando EM VOO por (ambiente, idempotency_key) — único parcial (padrão SourceDocActionLog).
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS connector_commands_inflight_idem
            ON connector_commands (environment_id, idempotency_key)
            WHERE idempotency_key IS NOT NULL AND status IN ('queued','claimed','running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_commands');
    }
};
