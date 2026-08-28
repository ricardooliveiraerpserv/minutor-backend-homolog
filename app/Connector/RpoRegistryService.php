<?php

namespace App\Connector;

use App\Models\ConnectorEnvironmentState;
use App\Models\ConnectorEvent;
use App\Models\EnvEnvironment;
use App\Models\RpoArtifact;
use App\Models\RpoQualification;
use App\Models\RpoTarget;
use App\Models\RpoTargetAppserver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Connector-5.1 — FUNDAÇÃO da publicação de RPO. SÓ saber/registrar/qualificar/agrupar/prever.
 * ZERO publicação: nenhum promote/rollback/claim/execution_committed/adapter/bytes/path. Nada aqui
 * cria connector_operation, execution_id ou efeito no agente. Autoridade de estado = RPO observado (C-2).
 */
class RpoRegistryService
{
    private function emit(int $envId, string $type, ?string $detail, array $meta): void
    {
        ConnectorEvent::create([
            'environment_id' => $envId, 'appserver_ref' => null, 'event_type' => $type,
            'outcome' => 'info', 'detail' => $detail, 'meta' => $meta, 'occurred_at' => now(),
        ]);
    }

    /** Estado observado (C-2) do ambiente. */
    private function observed(int $envId): ?array
    {
        $row = ConnectorEnvironmentState::where('environment_id', $envId)->first();
        if (! $row || $row->inventory_received_at === null) {
            return null;
        }
        $apps = collect($row->observed_json['appservers'] ?? [])->keyBy('ref');
        $rpo = collect($row->observed_json['rpo'] ?? [])->keyBy('appserver_ref');

        return [
            'appservers' => $apps, 'rpo' => $rpo, 'received_at' => $row->inventory_received_at,
            'stale_s' => max(0, now()->getTimestamp() - $row->inventory_received_at->getTimestamp()),
            'capability' => $row->rpo_capability,
        ];
    }

    /** Capability de publicação: declarada pelo agente E na allowlist (name+contract_version). Fail-closed. */
    public function capability(int $envId): array
    {
        $obs = $this->observed($envId);
        $cap = $obs['capability'] ?? null;
        $supported = (array) config('connector.operations.rpo.supported_capabilities', []);
        $available = false;
        if (is_array($cap) && ! empty($cap['name'])) {
            foreach ($supported as $s) {
                if (($s['name'] ?? null) === $cap['name'] && (int) ($s['contract_version'] ?? -1) === (int) ($cap['contract_version'] ?? -2)) {
                    $available = true;
                }
            }
        }

        return ['declared' => $cap, 'available' => $available, 'supported' => $supported];
    }

    /** Hashes DISCOVERED = RPOs observados AINDA sem registro (não confiáveis, não persistidos). */
    public function discovered(int $envId, ?int $customerId): array
    {
        $obs = $this->observed($envId);
        $hashes = collect($obs['rpo'] ?? [])->pluck('hash')->filter()->unique()->values();
        $registered = RpoArtifact::where('customer_id', $customerId)->whereNull('superseded_by_id')->pluck('hash')->flip();

        return $hashes->reject(fn ($h) => $registered->has($h))->map(fn ($h) => ['hash' => $h, 'status' => 'discovered'])->values()->all();
    }

    /** Registra um artefato (governado, imutável após criado). Hash sha256. provenance/compatibility exigidos. */
    public function register(int $envId, ?int $customerId, array $data, int $userId): array
    {
        if (! preg_match('/^[0-9a-f]{64}$/', (string) ($data['hash'] ?? ''))) {
            return ['ok' => false, 'error' => 'invalid_hash'];
        }
        if (empty($data['provenance']) || empty($data['compatibility'])) {
            return ['ok' => false, 'error' => 'provenance_and_compatibility_required']; // discovered→registered exige confiança
        }
        $art = RpoArtifact::create([
            'customer_id' => $customerId, 'hash' => $data['hash'], 'version' => $data['version'] ?? null,
            'provenance' => mb_substr($data['provenance'], 0, 300), 'compatibility' => $data['compatibility'],
            'source_identity' => isset($data['source_identity']) ? mb_substr($data['source_identity'], 0, 200) : null,
            'status' => 'registered', 'revision' => 1, 'registered_by' => $userId, 'registered_at' => now(),
            'classification' => $data['classification'] ?? null, // C5-FINAL metadata (não gateia nada)
        ]);
        $this->emit($envId, 'artifact_registered', 'Artefato registrado', ['artifact_id' => $art->id, 'hash' => substr($art->hash, 0, 12), 'revision' => 1]);

        return ['ok' => true, 'artifact' => $art];
    }

    /** Correção = NOVA REVISÃO (preserva o registro anterior; nunca edita hash/provenance/compatibility). */
    public function revise(RpoArtifact $old, array $data, int $userId): array
    {
        if ($old->superseded_by_id !== null) {
            return ['ok' => false, 'error' => 'already_superseded'];
        }
        if (empty($data['provenance']) || empty($data['compatibility'])) {
            return ['ok' => false, 'error' => 'provenance_and_compatibility_required'];
        }

        return DB::transaction(function () use ($old, $data, $userId) {
            $new = RpoArtifact::create([
                'customer_id' => $old->customer_id, 'hash' => $old->hash, // hash é IMUTÁVEL (mesma identidade)
                'version' => $data['version'] ?? $old->version,
                'provenance' => mb_substr($data['provenance'], 0, 300), 'compatibility' => $data['compatibility'],
                'source_identity' => isset($data['source_identity']) ? mb_substr($data['source_identity'], 0, 200) : $old->source_identity,
                'status' => 'registered', 'revision' => (int) $old->revision + 1, 'supersedes_id' => $old->id,
                'registered_by' => $userId, 'registered_at' => now(),
            ]);
            $old->update(['superseded_by_id' => $new->id]); // revisão anterior PRESERVADA (superseded)

            return ['ok' => true, 'artifact' => $new];
        });
    }

    /** Cria um target lógico (cadastral). 1 appserver_ref em no máx. 1 target ativo por ambiente. */
    public function createTarget(int $envId, ?int $customerId, string $name, array $appserverRefs, int $userId, ?string $classification = null): array
    {
        $refs = array_values(array_unique(array_filter($appserverRefs)));
        if (empty($refs)) {
            return ['ok' => false, 'error' => 'appservers_required'];
        }
        foreach ($refs as $r) {
            if (! preg_match('/^[0-9a-f-]{36}$/i', (string) $r)) {
                return ['ok' => false, 'error' => 'invalid_appserver_ref'];
            }
        }
        try {
            $target = DB::transaction(function () use ($envId, $customerId, $name, $refs, $userId, $classification) {
                $t = RpoTarget::create(['environment_id' => $envId, 'customer_id' => $customerId, 'name' => mb_substr($name, 0, 120), 'status' => 'pending_confirmation', 'created_by' => $userId, 'classification' => $classification]);
                foreach ($refs as $r) {
                    RpoTargetAppserver::create(['rpo_target_id' => $t->id, 'environment_id' => $envId, 'appserver_ref' => $r, 'created_at' => now()]);
                }

                return $t;
            });
        } catch (UniqueConstraintViolationException) {
            return ['ok' => false, 'error' => 'appserver_already_in_target']; // 1 target ativo por AppServer/ambiente
        }
        $this->emit($envId, 'rpo_target_created', 'Target de RPO criado', ['target_id' => $target->id, 'appservers' => count($refs)]);

        return ['ok' => true, 'target' => $target];
    }

    /** Consistência OBSERVACIONAL do target: todos os appservers observados, RPO fresco e MESMO hash. */
    public function targetConsistency(RpoTarget $target): array
    {
        $obs = $this->observed((int) $target->environment_id);
        $refs = $target->appservers()->pluck('appserver_ref')->all();
        $fresh = $obs && $obs['stale_s'] <= (int) config('connector.operations.observed_freshness', 120);
        $per = [];
        $hashes = [];
        $allObserved = true;
        foreach ($refs as $r) {
            $h = $obs['rpo'][$r]['hash'] ?? null;
            $per[] = ['appserver_ref' => $r, 'rpo_hash' => $h];
            if ($h === null) { $allObserved = false; } else { $hashes[$h] = true; }
        }
        $consistent = $fresh && $allObserved && count($hashes) === 1 && ! empty($refs);

        return ['consistent' => $consistent, 'fresh' => (bool) $fresh, 'all_observed' => $allObserved, 'hash' => $consistent ? array_key_first($hashes) : null, 'per_appserver' => $per];
    }

    /**
     * C5.2 — prova de UNIDADE FÍSICA ÚNICA: todos os AppServers do target reportam o MESMO publish_unit_id
     * (opaco, declarado pelo agente na observação de RPO). Se divergirem (APP01→U1, APP02→U2) o target NÃO é
     * uma única unidade de publicação → não promovível na v1. publish_unit_id ausente → não consistente.
     */
    public function publishUnitConsistency(RpoTarget $target): array
    {
        $obs = $this->observed((int) $target->environment_id);
        $refs = $target->appservers()->pluck('appserver_ref')->all();
        $per = [];
        $units = [];
        $allPresent = ! empty($refs);
        foreach ($refs as $r) {
            $u = $obs['rpo'][$r]['publish_unit_id'] ?? null;
            $per[] = ['appserver_ref' => $r, 'publish_unit_id' => $u];
            if ($u === null || $u === '') { $allPresent = false; } else { $units[$u] = true; }
        }
        $consistent = $allPresent && count($units) === 1;

        return ['consistent' => $consistent, 'publish_unit_id' => $consistent ? array_key_first($units) : null, 'per_appserver' => $per];
    }

    /** Confirma o target (exige consistência observacional). pending_confirmation → confirmed. */
    public function confirmTarget(RpoTarget $target, int $userId): array
    {
        $c = $this->targetConsistency($target);
        if (! $c['consistent']) {
            return ['ok' => false, 'error' => 'target_not_consistent', 'consistency' => $c];
        }
        $target->update(['status' => 'confirmed', 'confirmed_by' => $userId, 'confirmed_at' => now()]);
        $this->emit((int) $target->environment_id, 'rpo_target_confirmed', 'Target confirmado por observação', ['target_id' => $target->id, 'hash' => substr((string) $c['hash'], 0, 12)]);

        return ['ok' => true, 'target' => $target->fresh(), 'consistency' => $c];
    }

    /** Qualifica um artefato REGISTERED como known_good CONTEXTUAL (artifact × target). Histórico preservado. */
    public function qualify(RpoTarget $target, RpoArtifact $artifact, string $reason, int $userId, ?string $classification = null): array
    {
        if ($artifact->status !== 'registered' || $artifact->superseded_by_id !== null) {
            return ['ok' => false, 'error' => 'artifact_not_registered'];
        }
        if (empty($artifact->provenance) || empty($artifact->compatibility)) {
            return ['ok' => false, 'error' => 'provenance_and_compatibility_required'];
        }
        if (empty(trim($reason))) {
            return ['ok' => false, 'error' => 'reason_required'];
        }
        $q = RpoQualification::create([
            'rpo_artifact_id' => $artifact->id, 'rpo_target_id' => $target->id, 'environment_id' => $target->environment_id,
            'hash' => $artifact->hash, 'qualified_by' => $userId, 'reason' => mb_substr($reason, 0, 300), 'qualified_at' => now(),
            'classification' => $classification,
        ]);
        $this->emit((int) $target->environment_id, 'artifact_qualified_known_good', 'Artefato qualificado known_good', ['artifact_id' => $artifact->id, 'target_id' => $target->id, 'hash' => substr($artifact->hash, 0, 12)]);

        return ['ok' => true, 'qualification' => $q];
    }

    public function revokeQualification(RpoQualification $q, int $userId): array
    {
        if ($q->revoked_at !== null) {
            return ['ok' => false, 'error' => 'already_revoked'];
        }
        $q->update(['revoked_at' => now()]);
        $this->emit((int) $q->environment_id, 'artifact_known_good_revoked', 'Qualificação known_good revogada', ['qualification_id' => $q->id, 'artifact_id' => $q->rpo_artifact_id]);

        return ['ok' => true, 'qualification' => $q->fresh()];
    }

    /** last_known_good = DERIVADO da qualificação válida (não revogada) mais recente do target. */
    public function lastKnownGood(RpoTarget $target): ?RpoQualification
    {
        return RpoQualification::where('rpo_target_id', $target->id)->whereNull('revoked_at')->orderByDesc('qualified_at')->orderByDesc('id')->first();
    }

    /**
     * PREVIEW read-only de uma futura transição from→to. NÃO cria connector_operation/execution_id/claim.
     * Valida: to registered (não discovered); compatibility × observado; target confirmado+consistente;
     * capability disponível. Snapshot de política N-of-M. Congelável na operação futura.
     */
    public function preview(RpoTarget $target, RpoArtifact $to, bool $isRollback = false): array
    {
        $reasons = [];
        // to deve ser registered vigente (discovered nunca é destino).
        if ($to->status !== 'registered' || $to->superseded_by_id !== null) {
            $reasons[] = 'to_not_registered';
        }
        // rollback só para known_good (contextual do target).
        if ($isRollback) {
            $isKg = RpoQualification::where('rpo_target_id', $target->id)->where('rpo_artifact_id', $to->id)->whereNull('revoked_at')->exists();
            if (! $isKg) { $reasons[] = 'rollback_target_not_known_good'; }
        }
        // target confirmado + consistente.
        if ($target->status !== 'confirmed') { $reasons[] = 'target_not_confirmed'; }
        $c = $this->targetConsistency($target);
        if (! $c['consistent']) { $reasons[] = 'target_not_consistent'; }
        $fromHash = $c['hash'];
        // compatibility do to × versões observadas dos AppServers do target.
        $obs = $this->observed((int) $target->environment_id);
        $compatVersions = (array) ($to->compatibility['appserver_versions'] ?? []);
        $refs = $target->appservers()->pluck('appserver_ref')->all();
        $incompat = [];
        foreach ($refs as $r) {
            $v = $obs['appservers'][$r]['version'] ?? null;
            if ($v !== null && ! empty($compatVersions) && ! in_array($v, $compatVersions, true)) { $incompat[] = ['appserver_ref' => $r, 'version' => $v]; }
        }
        if (! empty($incompat)) { $reasons[] = 'incompatible_appserver_version'; }
        // capability disponível.
        $cap = $this->capability((int) $target->environment_id);
        if (! $cap['available']) { $reasons[] = 'publish_capability_unavailable'; }
        // no-op declarado: from já == to.
        if ($fromHash !== null && $fromHash === $to->hash) { $reasons[] = 'from_equals_to'; }

        $env = EnvEnvironment::query()->whereKey($target->environment_id)->first(['type']);
        $policy = (array) config('connector.operations.rpo.required_approvals', []);
        $requiredApprovals = (int) ($policy[$env->type ?? 'default'] ?? $policy['default'] ?? 1);

        return [
            'kind' => $isRollback ? 'rpo_rollback' : 'rpo_promote',
            'target_id' => $target->id, 'target_consistency' => $c,
            'from' => $fromHash ? ['hash' => $fromHash] : null,
            'to' => ['artifact_id' => $to->id, 'hash' => $to->hash, 'version' => $to->version, 'provenance' => $to->provenance, 'compatibility' => $to->compatibility, 'revision' => $to->revision],
            'incompatibilities' => $incompat,
            'capability' => $cap,
            'policy_snapshot' => ['env_type' => $env->type ?? null, 'required_approvals' => $requiredApprovals],
            'eligible' => empty($reasons),
            'reasons' => $reasons,
            'note' => 'Preview read-only — NÃO cria operação, execution_id ou efeito. Publicação é C5.2+.',
        ];
    }

    /**
     * C5.3 — REGRA DE DOMÍNIO ÚNICA do rollback (usada por preview [informar] E por createRollback/dispatch
     * [autorizar], reavaliada com o estado CORRENTE em cada barreira — nunca confia no snapshot da consulta).
     * Destino = uma QUALIFICAÇÃO known_good CONTEXTUAL, NOMEADA (qualification_id) — não "última", não outro
     * env/target, não "registered qualquer". from_hash = OBSERVADO ATUAL (mundo), não a operação de promote.
     * already_at_rollback_target é por HASH EFETIVO (observed == qualif.hash), mesmo com artifact_id distinto.
     */
    public function evaluateRollback(RpoTarget $target, RpoQualification $q): array
    {
        $reasons = [];
        // Contexto: a qualificação precisa ser DESTE target E environment (não de outro contexto).
        if ((int) $q->rpo_target_id !== (int) $target->id || (int) $q->environment_id !== (int) $target->environment_id) {
            $reasons[] = 'qualification_wrong_context';
        }
        // Qualificação válida (não revogada) — autoridade que PERMITE o rollback.
        if ($q->revoked_at !== null) {
            $reasons[] = 'qualification_revoked';
        }
        // Target confirmado + consistente (from_hash observado).
        if ($target->status !== 'confirmed') { $reasons[] = 'target_not_confirmed'; }
        $c = $this->targetConsistency($target);
        if (! $c['consistent']) { $reasons[] = 'target_not_consistent'; }
        $fromHash = $c['hash'];
        $toHash = $q->hash; // hash IMUTÁVEL da qualificação (identidade física do destino de recuperação)
        // Já no destino — decisão pelo HASH efetivo, não pelo artifact_id (código PRÓPRIO, não from_equals_to).
        if ($fromHash !== null && $fromHash === $toHash) { $reasons[] = 'already_at_rollback_target'; }
        // Compatibilidade do artefato qualificado × versões observadas dos AppServers do target.
        $art = RpoArtifact::find($q->rpo_artifact_id);
        $obs = $this->observed((int) $target->environment_id);
        $compatVersions = (array) ($art->compatibility['appserver_versions'] ?? []);
        $refs = $target->appservers()->pluck('appserver_ref')->all();
        $incompat = [];
        foreach ($refs as $r) {
            $v = $obs['appservers'][$r]['version'] ?? null;
            if ($v !== null && ! empty($compatVersions) && ! in_array($v, $compatVersions, true)) { $incompat[] = ['appserver_ref' => $r, 'version' => $v]; }
        }
        if (! empty($incompat)) { $reasons[] = 'incompatible_appserver_version'; }
        // Capability disponível + activation_mode EXECUTÁVEL (hot). fail-closed.
        $cap = $this->capability((int) $target->environment_id);
        if (! $cap['available']) { $reasons[] = 'publish_capability_unavailable'; }
        $mode = $cap['declared']['activation_mode'] ?? null;
        $execModes = (array) config('connector.operations.rpo.executable_activation_modes', ['hot']);
        if (! in_array($mode, $execModes, true)) { $reasons[] = 'activation_mode_not_executable'; }
        // Unidade física ÚNICA.
        $pu = $this->publishUnitConsistency($target);
        if (! $pu['consistent']) { $reasons[] = 'publish_unit_inconsistent'; }

        return [
            'eligible' => empty($reasons), 'reasons' => $reasons,
            'from_hash' => $fromHash, 'to_hash' => $toHash, 'to_artifact_id' => (int) $q->rpo_artifact_id,
            'qualification_id' => (int) $q->id, 'activation_mode' => $mode,
            'publish_unit_id' => $pu['publish_unit_id'], 'publish_unit' => $pu,
            'target_consistency' => $c, 'capability' => $cap, 'incompatibilities' => $incompat,
            'qualification' => ['id' => $q->id, 'artifact_id' => $q->rpo_artifact_id, 'hash' => $q->hash, 'reason' => $q->reason, 'qualified_by' => $q->qualified_by, 'qualified_at' => $q->qualified_at?->toIso8601String()],
        ];
    }

    /**
     * PREVIEW read-only do rollback (projeção INFORMATIVA da regra de domínio): a qualificação selecionada +
     * last_known_good (se diferente) + demais qualificações válidas do contexto (para a decisão humana ser
     * inequívoca). Só a SELECIONADA entra na autoridade da operação. NÃO cria operação/execution_id/efeito.
     */
    public function rollbackPreview(RpoTarget $target, RpoQualification $q): array
    {
        $ev = $this->evaluateRollback($target, $q);
        $lkg = $this->lastKnownGood($target);
        $others = RpoQualification::where('rpo_target_id', $target->id)->whereNull('revoked_at')
            ->where('id', '!=', $q->id)->orderByDesc('qualified_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn ($x) => ['qualification_id' => $x->id, 'artifact_id' => $x->rpo_artifact_id, 'hash' => $x->hash, 'reason' => $x->reason, 'qualified_at' => $x->qualified_at?->toIso8601String()])->all();

        return [
            'kind' => 'rpo_rollback',
            'target_id' => $target->id, 'selected' => $ev['qualification'],
            'from' => $ev['from_hash'] ? ['hash' => $ev['from_hash']] : null,
            'to' => ['artifact_id' => $ev['to_artifact_id'], 'hash' => $ev['to_hash']],
            'last_known_good' => $lkg && (int) $lkg->id !== (int) $q->id ? ['qualification_id' => $lkg->id, 'artifact_id' => $lkg->rpo_artifact_id, 'hash' => $lkg->hash] : null,
            'other_valid_known_good' => $others, // INFORMATIVO — não entra na autoridade; surgir novo não invalida
            'target_consistency' => $ev['target_consistency'], 'capability' => $ev['capability'],
            'incompatibilities' => $ev['incompatibilities'],
            'eligible' => $ev['eligible'], 'reasons' => $ev['reasons'],
            'note' => 'Preview read-only — informa; a criação REAVALIA a regra transacionalmente. NÃO cria operação.',
        ];
    }
}
