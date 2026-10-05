<?php

namespace App\Http\Controllers;

use App\Connector\ConnectorCommandService;
use App\Models\ConnectorAgent;
use App\Models\ConnectorCommand;
use App\Models\EnvEnvironment;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connector-3 — orquestração de comandos assíncronos NÃO destrutivos.
 *  - AGENTE (assinado, connector.agent, OUTBOUND-ONLY): long-poll claim (next), ack, result.
 *    O escopo (ambiente) vem SEMPRE do registro do agente, nunca do payload.
 *  - ADMIN (sessão + escopo): criar (perm execute), listar/ver (perm view), cancelar (perm execute).
 * Único command_type aceito: collect_inventory_now (dispara o pipeline C-2 já homologado).
 * Nenhum secret/path/INI/bytes cruzam a fronteira; nenhuma execução síncrona no request.
 */
class ConnectorCommandController extends Controller
{
    public function __construct(
        private ConnectorCommandService $commands,
        private SourceDocCustomerScope $scope,
    ) {
    }

    // ── AGENTE (outbound-only) ────────────────────────────────────────────────

    /**
     * GET /connector/commands/next — LONG-POLL. Reivindica 1 comando do ambiente do agente (claim
     * atômico, attempts++). hold configurável (config commands.longpoll_hold), 0 = short-poll. 204 se vazio.
     */
    public function next(Request $request): JsonResponse
    {
        /** @var ConnectorAgent $agent */
        $agent = $request->attributes->get('connector_agent');
        $hold = max(0, (int) config('connector.commands.longpoll_hold', 25));
        $deadline = microtime(true) + $hold;

        do {
            $this->commands->reapEnvironment((int) $agent->environment_id); // reaper LAZY (leases/TTL)
            $cmd = $this->commands->claimNext($agent);
            if ($cmd) {
                return response()->json(['data' => [
                    'id'               => $cmd->id,
                    'command_type'     => $cmd->command_type,
                    'params'           => $cmd->params ?: (object) [],
                    'claim_token'      => $cmd->claim_token,
                    'claim_expires_at' => $cmd->claim_expires_at?->toIso8601String(),
                    'attempt'          => $cmd->attempts,
                    'server_time'      => now()->timestamp,
                ]]);
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(1_000_000); // 1s entre tentativas dentro do hold
        } while (true);

        return response()->json(null, 204);
    }

    /** POST /connector/commands/{id}/ack {claim_token} — claimed→running (opcional). */
    public function ack(Request $request, int $id): JsonResponse
    {
        /** @var ConnectorAgent $agent */
        $agent = $request->attributes->get('connector_agent');
        $data = $request->validate(['claim_token' => 'required|string|max:64']);
        $cmd = $this->agentCommand($agent, $id);
        if (! $cmd) {
            return response()->json(['message' => 'Comando não encontrado.'], 404);
        }
        $ok = $this->commands->ack($agent, $cmd, $data['claim_token']);

        return response()->json(['ok' => $ok], $ok ? 200 : 409);
    }

    /**
     * POST /connector/commands/{id}/result {claim_token, outcome, duration_ms?, observed_at?, error?}
     * Aceito só com claim_token do claim vigente + estado {claimed,running}. Senão 409 stale_result.
     * NUNCA recebe/loga bytes de inventário — o inventário sobe pelo canal C-2 (/connector/inventory).
     */
    public function result(Request $request, int $id): JsonResponse
    {
        /** @var ConnectorAgent $agent */
        $agent = $request->attributes->get('connector_agent');
        $data = $request->validate([
            'claim_token' => 'required|string|max:64',
            'outcome'     => 'required|in:ok,fail',
            'duration_ms' => 'nullable|integer|min:0',
            'observed_at' => 'nullable|integer',
            'error'       => 'nullable|string|max:200',
        ]);
        $cmd = $this->agentCommand($agent, $id);
        if (! $cmd) {
            return response()->json(['message' => 'Comando não encontrado.'], 404);
        }
        $detail = isset($data['error']) ? $this->sanitize((string) $data['error']) : null;
        $meta = array_filter([
            'duration_ms' => $data['duration_ms'] ?? null,
            'observed_at' => $data['observed_at'] ?? null,
        ], fn ($v) => $v !== null);

        $r = $this->commands->result($agent, $cmd, $data['claim_token'], $data['outcome'], $detail, $meta);
        if (! $r['ok']) {
            $status = $r['status'] === 'not_found' ? 404 : 409;

            return response()->json(['error' => $r['status']], $status); // stale_result / terminal → 409
        }

        return response()->json(['ok' => true, 'status' => $r['status']]);
    }

    // ── ADMIN (sessão + escopo) ───────────────────────────────────────────────

    /**
     * POST /prosight/environments/{environmentId}/commands {command_type, idempotency_key?}
     * Perm prosight.operations.execute (estrita). Allowlist de tipo (422 fora dela). Anti-IDOR 404.
     */
    public function create(Request $request, int $environmentId): JsonResponse
    {
        $env = EnvEnvironment::query()->whereKey($environmentId)->first(['id', 'customer_id']);
        if (! $env || ! $this->scope->canAccessCustomerId($request->user(), (int) $env->customer_id)) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }
        $data = $request->validate([
            'command_type'    => 'required|string|max:40',
            'idempotency_key' => 'nullable|string|max:80',
        ]);
        $allow = (array) config('connector.commands.types', []);
        if (! in_array($data['command_type'], $allow, true)) {
            // start/stop/restart/compile/patch/promote/rollback e quaisquer outros → rejeitados na porta.
            return response()->json(['error' => 'command_type_not_allowed', 'allowed' => array_values($allow)], 422);
        }

        $r = $this->commands->enqueue((int) $env->id, (int) $env->customer_id, $data['command_type'],
            $request->user()?->id, $data['idempotency_key'] ?? null);

        return response()->json(['data' => $this->adminView($r['command']), 'coalesced' => $r['coalesced']],
            $r['coalesced'] ? 200 : 201);
    }

    /** GET /prosight/environments/{environmentId}/commands — lista recente (perm view). Anti-IDOR 404. */
    public function index(Request $request, int $environmentId): JsonResponse
    {
        $env = EnvEnvironment::query()->whereKey($environmentId)->first(['id', 'customer_id']);
        if (! $env || ! $this->scope->canAccessCustomerId($request->user(), (int) $env->customer_id)) {
            return response()->json(['message' => 'Ambiente não encontrado.'], 404);
        }
        $this->commands->reapEnvironment((int) $env->id); // reflete expiry ao listar
        $rows = ConnectorCommand::where('environment_id', $env->id)->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => [
            'environment_id' => (int) $env->id,
            'commands'       => $rows->map(fn ($c) => $this->adminView($c))->all(),
        ]]);
    }

    /** GET /prosight/commands/{commandId} — detalhe (perm view). Anti-IDOR 404. */
    public function show(Request $request, int $commandId): JsonResponse
    {
        $cmd = ConnectorCommand::whereKey($commandId)->first();
        if (! $cmd || ! $this->scope->canAccessCustomerId($request->user(), (int) $cmd->customer_id)) {
            return response()->json(['message' => 'Comando não encontrado.'], 404);
        }

        return response()->json(['data' => $this->adminView($cmd)]);
    }

    /** POST /prosight/commands/{commandId}/cancel — queued→canceled; claimed/running→409 (perm execute). */
    public function cancel(Request $request, int $commandId): JsonResponse
    {
        $cmd = ConnectorCommand::whereKey($commandId)->first();
        if (! $cmd || ! $this->scope->canAccessCustomerId($request->user(), (int) $cmd->customer_id)) {
            return response()->json(['message' => 'Comando não encontrado.'], 404);
        }
        $r = $this->commands->cancel($cmd);
        if (! $r['ok']) {
            $code = $r['status'] === 'not_found' ? 404 : 409;

            return response()->json(['error' => $r['status'] === 'already_running' ? 'command_already_running' : $r['status']], $code);
        }

        return response()->json(['data' => $this->adminView($cmd->fresh())]);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** Resolve um comando garantindo que pertence ao AMBIENTE do agente (anti-IDOR; escopo server-side). */
    private function agentCommand(ConnectorAgent $agent, int $id): ?ConnectorCommand
    {
        $cmd = ConnectorCommand::whereKey($id)->first();

        return ($cmd && (int) $cmd->environment_id === (int) $agent->environment_id) ? $cmd : null;
    }

    /** Projeção segura p/ a sessão. NÃO expõe claim_token (capability do agente). */
    private function adminView(ConnectorCommand $c): array
    {
        return [
            'id'                   => $c->id,
            'environment_id'       => (int) $c->environment_id,
            'command_type'         => $c->command_type,
            'status'               => $c->status,
            'attempts'             => (int) $c->attempts,
            'max_attempts'         => (int) $c->max_attempts,
            'requested_by'         => $c->requested_by,
            'claimed_by_agent_id'  => $c->claimed_by_agent_id,
            'result_outcome'       => $c->result_outcome,
            'result_detail'        => $c->result_detail,
            'result_meta'          => $c->result_meta,
            'inventory_applied_at' => $c->inventory_applied_at?->toIso8601String(),
            'correlated'           => $c->inventory_applied_at !== null, // correlação FORTE (não temporal)
            'enqueued_at'          => $c->enqueued_at?->toIso8601String(),
            'claimed_at'           => $c->claimed_at?->toIso8601String(),
            'claim_expires_at'     => $c->claim_expires_at?->toIso8601String(),
            'finished_at'          => $c->finished_at?->toIso8601String(),
            'created_at'           => $c->created_at?->toIso8601String(),
        ];
    }

    private function sanitize(string $e): string
    {
        $e = mb_substr(trim($e), 0, 200);

        return preg_match('/secret|password|token|connection|\.ini|senha/i', $e) ? '[redacted]' : $e;
    }
}
