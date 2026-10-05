<?php

namespace App\Connector;

use App\Models\ConnectorAgent;
use App\Models\ConnectorAppserverBinding;
use App\Models\ConnectorEnvironmentState;
use App\Models\ConnectorEvent;
use App\Models\EnvAppserver;
use App\Models\EnvEnvironment;
use App\Models\RpoTarget;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Facades\DB;

/**
 * ENV-HUB — orquestra a jornada por AMBIENTE (Connector→AppServers→RPO→Operações) SEM duplicar Cofre/Prosight/C5.
 * Read-model operational-status (readiness+blocking_reasons; estados DERIVADOS) + reconciliação CADASTRAL↔OBSERVADO
 * (binding humano, nunca auto; supersede não-destrutivo). Zero secret/INI/path. process_instance_id NÃO usado como
 * identidade (efêmero); vínculo é ao appserver_ref (lógico observado, estável a restart).
 */
class EnvironmentHubService
{
    public function __construct(
        private RpoTopologyService $topo,
        private PresenceDeriver $presence,
    ) {
    }

    // ── Connector (status derivado; sem segredo) ───────────────────────────────
    private function connector(int $envId): array
    {
        $agent = ConnectorAgent::where('environment_id', $envId)->orderByDesc('id')->first();
        if (! $agent) { return ['status' => 'not_enrolled', 'last_seen_at' => null, 'connector_id' => null, 'since_s' => null]; }
        if ($agent->revoked_at) { return ['status' => 'revoked', 'last_seen_at' => null, 'connector_id' => $agent->agent_id, 'since_s' => null]; }
        $st = ConnectorEnvironmentState::where('environment_id', $envId)->first();
        $d = $this->presence->derive($st?->last_seen_at, $st?->agent_reported_status, $st?->clock_offset_s, $st?->last_error);
        return ['status' => $d['status'], 'since_s' => $d['since_s'] ?? null, 'last_seen_at' => optional($st?->last_seen_at)->toIso8601String(), 'connector_id' => $agent->agent_id];
    }

    private function observed(int $envId): array
    {
        $st = ConnectorEnvironmentState::where('environment_id', $envId)->first();
        $out = [];
        foreach (($st?->observed_json['appservers'] ?? []) as $a) {
            if (! empty($a['ref'])) { $out[$a['ref']] = $a; }
        }
        return $out;
    }

    // ── Reconciliação: cadastral + observado + bindings → estados DERIVADOS ─────
    public function reconciliation(int $envId): array
    {
        $connector = $this->connector($envId);
        $connFresh = in_array($connector['status'], ['online', 'degraded'], true);
        $currId = $connector['connector_id'];
        $cad = EnvAppserver::where('environment_id', $envId)->get();
        $obs = $this->observed($envId);
        $bindings = ConnectorAppserverBinding::where('environment_id', $envId)->where('status', ConnectorAppserverBinding::ST_ACTIVE)->get();
        $bByCad = $bindings->keyBy('env_appserver_id');
        $boundRefs = $bindings->pluck('appserver_ref')->all();

        $rows = [];
        foreach ($cad as $c) {
            $b = $bByCad->get($c->id);
            if ($b) {
                $o = $obs[$b->appserver_ref] ?? null;
                if ($o) { $state = ($o['up'] ?? false) ? 'healthy' : 'bound_down'; }
                elseif ($b->connector_id && $currId && $b->connector_id !== $currId) { $state = 'connector_replaced'; }
                elseif (! $connFresh) { $state = 'connector_stale'; }
                else { $state = 'not_observed'; }
                $rows[] = ['kind' => 'cadastral', 'env_appserver_id' => $c->id, 'name' => $c->name, 'appserver_ref' => $b->appserver_ref, 'binding_id' => $b->id, 'observed_up' => $o['up'] ?? null, 'state' => $state];
            } else {
                // Sugestão NÃO-autoritativa por nome (0/1/ambíguo). Nunca auto-vincula.
                $cands = collect($obs)->filter(fn ($o) => ! in_array($o['ref'], $boundRefs, true) && strcasecmp((string) ($o['name'] ?? ''), (string) $c->name) === 0)->values();
                $rows[] = ['kind' => 'cadastral', 'env_appserver_id' => $c->id, 'name' => $c->name, 'appserver_ref' => null, 'binding_id' => null, 'state' => 'unbound_cadastral',
                    'suggestion' => $cands->count() === 1 ? ['appserver_ref' => $cands[0]['ref'], 'name' => $cands[0]['name'] ?? null] : null,
                    'suggestion_ambiguous' => $cands->count() > 1];
            }
        }
        // cadastral_missing: binding ativo cujo cadastral sumiu (soft-deleted). Advisory; não apaga o binding.
        foreach ($bindings as $b) {
            if (! $cad->firstWhere('id', $b->env_appserver_id)) {
                $rows[] = ['kind' => 'binding_orphan', 'env_appserver_id' => $b->env_appserver_id, 'appserver_ref' => $b->appserver_ref, 'binding_id' => $b->id, 'state' => 'cadastral_missing'];
            }
        }
        // Observado sem binding → detectado_não_vinculado (+ sugestão de cadastral por nome).
        foreach ($obs as $ref => $o) {
            if (! in_array($ref, $boundRefs, true)) {
                $cands = $cad->filter(fn ($c) => ! $bByCad->get($c->id) && strcasecmp((string) $c->name, (string) ($o['name'] ?? '')) === 0)->values();
                $rows[] = ['kind' => 'observed', 'appserver_ref' => $ref, 'name' => $o['name'] ?? null, 'observed_up' => (bool) ($o['up'] ?? false), 'state' => 'detected_unbound',
                    'suggestion' => $cands->count() === 1 ? ['env_appserver_id' => $cands[0]->id, 'name' => $cands[0]->name] : null,
                    'suggestion_ambiguous' => $cands->count() > 1];
            }
        }
        return ['connector' => $connector, 'rows' => $rows];
    }

    private const DIVERGENT_STATES = ['not_observed', 'connector_replaced', 'cadastral_missing', 'conflict'];

    // ── operational-status (read-model executivo) ──────────────────────────────
    public function operationalStatus(EnvEnvironment $env, User $user): array
    {
        $envId = (int) $env->id;
        $rec = $this->reconciliation($envId);
        $connector = $rec['connector'];
        $rows = collect($rec['rows']);
        $obs = $this->observed($envId);

        $configured = EnvAppserver::where('environment_id', $envId)->count();
        $observedN = count($obs);
        $bound = ConnectorAppserverBinding::where('environment_id', $envId)->where('status', ConnectorAppserverBinding::ST_ACTIVE)->count();
        $up = collect($obs)->filter(fn ($o) => $o['up'] ?? false)->count();
        $divergent = $rows->whereIn('state', self::DIVERGENT_STATES)->count();
        $unbound = $rows->where('state', 'detected_unbound')->count();

        $topoView = $this->topo->view($envId);
        $confirmedTargets = RpoTarget::where('environment_id', $envId)->where('status', 'confirmed')->count();
        $rpoDiscovery = $topoView['observation'] ? ($confirmedTargets > 0 ? 'confirmed' : 'detected') : 'none';
        $rpoDivergence = count($topoView['divergences']) > 0;
        $consistency = $confirmedTargets > 0 ? ($rpoDivergence ? 'divergent' : 'consistent') : 'na';

        // Jornada (Configuração N de 4)
        $connOnline = in_array($connector['status'], ['online', 'degraded'], true);
        $steps = [
            ['key' => 'environment', 'label' => 'Ambiente criado', 'done' => true],
            ['key' => 'connector', 'label' => 'Connector online', 'done' => $connOnline],
            ['key' => 'appservers', 'label' => 'AppServers vinculados', 'done' => $observedN > 0 && $unbound === 0 && $bound > 0],
            ['key' => 'rpo', 'label' => 'RPO confirmado', 'done' => $confirmedTargets > 0],
        ];
        $done = count(array_filter($steps, fn ($s) => $s['done']));
        $next = null;
        foreach ($steps as $s) { if (! $s['done']) { $next = $s['key']; break; } }

        // readiness + blocking_reasons (derivado; não é autoridade)
        $blocking = [];
        if ($connector['status'] === 'not_enrolled') { $blocking[] = 'connector_not_enrolled'; }
        elseif ($connector['status'] === 'revoked') { $blocking[] = 'connector_revoked'; }
        elseif (! $connOnline) { $blocking[] = 'connector_offline'; }
        if ($observedN === 0 && $configured === 0) { $blocking[] = 'no_appservers'; }
        if ($unbound > 0) { $blocking[] = 'appservers_unbound'; }
        if ($confirmedTargets === 0) { $blocking[] = 'rpo_not_confirmed'; }

        $attention = [];
        if ($divergent > 0) { $attention[] = 'appservers_divergent'; }
        if ($rpoDivergence) { $attention[] = 'rpo_divergent'; }
        if ($connector['status'] === 'stale') { $attention[] = 'connector_stale'; }

        $readiness = $blocking ? 'setup_required' : ($attention ? 'attention' : 'ready');

        $perms = PermissionService::for($user);
        $can = fn (string $p) => $user->isAdmin() || in_array($p, $perms, true);

        return [
            'environment' => ['id' => $env->id, 'name' => $env->name, 'type' => $env->type, 'status' => $env->status],
            'connector' => $connector,
            'appservers' => ['configured' => $configured, 'observed' => $observedN, 'bound' => $bound, 'divergent' => $divergent, 'up' => $up, 'down' => max(0, $observedN - $up), 'unbound' => $unbound],
            'rpo' => ['discovery_status' => $rpoDiscovery, 'targets' => count($topoView['suggestions']), 'confirmed' => $confirmedTargets, 'consistency' => $consistency, 'divergence' => $rpoDivergence],
            'readiness' => $readiness,
            'blocking_reasons' => array_values($blocking),
            'attention_reasons' => array_values($attention),
            'journey' => ['progress' => $done, 'total' => count($steps), 'steps' => $steps, 'next_step' => $next],
            'actions' => [
                'can_manage_environment' => $can('environments.use'),
                'can_manage_connector' => $can('prosight.operations.manage'),
                'can_collect' => $can('prosight.operations.execute'),
                'can_bind' => $can('prosight.operations.appserver.bind'),
                'can_manage_rpo' => $can('prosight.operations.rpo.manage'),
                'can_operate_rpo' => $can('prosight.operations.rpo.promote') || $can('prosight.operations.rpo.rollback'),
            ],
        ];
    }

    // ── E2 — vínculo HUMANO (nunca auto). Rebind = supersede não-destrutivo + novo com supersedes_binding_id. ──
    public function confirmBinding(EnvEnvironment $env, int $envAppserverId, string $ref, int $userId): array
    {
        $envId = (int) $env->id;
        $cad = EnvAppserver::where('environment_id', $envId)->whereKey($envAppserverId)->first();
        if (! $cad) { return ['ok' => false, 'error' => 'cadastral_not_found', 'status' => 404]; }
        $obs = $this->observed($envId);
        if (! isset($obs[$ref])) { return ['ok' => false, 'error' => 'ref_not_observed', 'status' => 422]; } // só vincula o que se vê
        // ref já vinculado a OUTRO cadastral ativo → conflito 1:1 (não supersede o alheio automaticamente).
        $refActive = ConnectorAppserverBinding::where('environment_id', $envId)->where('appserver_ref', $ref)->where('status', ConnectorAppserverBinding::ST_ACTIVE)->first();
        if ($refActive && (int) $refActive->env_appserver_id !== $envAppserverId) {
            return ['ok' => false, 'error' => 'ref_already_bound', 'status' => 409, 'conflict_env_appserver_id' => $refActive->env_appserver_id];
        }
        $connector = $this->connector($envId);

        $binding = DB::transaction(function () use ($envId, $envAppserverId, $ref, $userId, $connector, $obs) {
            $prev = ConnectorAppserverBinding::where('environment_id', $envId)->where('env_appserver_id', $envAppserverId)->where('status', ConnectorAppserverBinding::ST_ACTIVE)->lockForUpdate()->first();
            $supersedesId = null;
            if ($prev) {
                if ($prev->appserver_ref === $ref) { return $prev; } // já vinculado ao mesmo ref (idempotente)
                $prev->update(['status' => ConnectorAppserverBinding::ST_SUPERSEDED, 'superseded_at' => now(), 'superseded_by' => $userId, 'reason' => 'rebind']);
                $supersedesId = $prev->id;
            }
            return ConnectorAppserverBinding::create([
                'environment_id' => $envId, 'env_appserver_id' => $envAppserverId, 'connector_id' => $connector['connector_id'],
                'appserver_ref' => $ref, 'status' => ConnectorAppserverBinding::ST_ACTIVE, 'supersedes_binding_id' => $supersedesId,
                'bound_by' => $userId, 'bound_at' => now(), 'last_observed_at' => now(),
            ]);
        });
        $this->emit($envId, 'appserver_binding_confirmed', 'Vínculo AppServer confirmado', ['binding_id' => $binding->id, 'env_appserver_id' => $envAppserverId, 'appserver_ref' => substr($ref, 0, 8), 'supersedes' => $binding->supersedes_binding_id]);
        return ['ok' => true, 'binding' => $binding];
    }

    // Supersede explícito (não-destrutivo) — encerra um vínculo sem apagar histórico.
    public function supersedeBinding(EnvEnvironment $env, ConnectorAppserverBinding $b, string $reason, int $userId): array
    {
        if ((int) $b->environment_id !== (int) $env->id) { return ['ok' => false, 'error' => 'not_found', 'status' => 404]; }
        if ($b->status !== ConnectorAppserverBinding::ST_ACTIVE) { return ['ok' => false, 'error' => 'not_active', 'status' => 409]; }
        $b->update(['status' => ConnectorAppserverBinding::ST_SUPERSEDED, 'superseded_at' => now(), 'superseded_by' => $userId, 'reason' => mb_substr($reason, 0, 300)]);
        $this->emit((int) $env->id, 'appserver_binding_superseded', 'Vínculo AppServer encerrado', ['binding_id' => $b->id, 'reason' => mb_substr($reason, 0, 120)]);
        return ['ok' => true];
    }

    private function emit(int $envId, string $type, ?string $detail, array $meta): void
    {
        ConnectorEvent::create(['environment_id' => $envId, 'appserver_ref' => null, 'event_type' => $type, 'outcome' => 'info', 'detail' => $detail, 'meta' => $meta, 'occurred_at' => now()]);
    }
}
