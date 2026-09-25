<?php

namespace App\Connector;

use App\Models\ConnectorEnvironmentState;
use App\Models\EnvEnvironment;
use App\Models\PatchInput;
use App\Models\PatchRequest;
use App\Models\PatchRequestItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PATCH P1 — FUNDAÇÃO. Cadastro seguro de PatchInput + criação de PatchRequest (base_rpo_hash CONGELADO, lote
 * ORDENADO/IMUTÁVEL, digest por item + batch_digest, mode-gating fail-closed, availability). P1 NÃO executa/aplica
 * patch, NÃO registra no C5. Boundary: Patch produz candidate; C5 publica. Zero bytes/path/INI/PTM/secret.
 */
class PatchService
{
    // Sanitização defensiva (idêntica em espírito ao C6): nada de path/secret cruza, mesmo se algo escapar.
    private const FORBIDDEN = '#(/[A-Za-z0-9_.\-]+){2,}|[A-Za-z]:\\\\[^\s]*|\b(?:password|secret|special ?key|token|api[_-]?key|private[ _]?key|connection ?string)\b\s*[:=]?\s*\S*|BEGIN [A-Z ]+PRIVATE KEY|\bEnv[A-Z]\w*#i';

    private function safe(string $s): string
    {
        return (string) preg_replace(self::FORBIDDEN, '[redacted]', mb_substr($s, 0, 300));
    }

    public function patchCapability(int $envId): ?array
    {
        $cap = ConnectorEnvironmentState::where('environment_id', $envId)->first()?->patch_capability;
        return is_array($cap) ? $cap : null;
    }

    /**
     * Disponibilidade por modo (fail-closed; SEM fallback). Prova: capability incompatível → unavailable; live
     * unavailable enquanto live_ready=false / capability ausente / contrato não suportado.
     */
    public function availability(EnvEnvironment $env): array
    {
        $fixtureOk = (bool) config('connector.patch.allow_fixture', false);
        $modes = (array) config('connector.patch.executable_modes', ['simulated']);
        $simOk = in_array(PatchRequest::MODE_SIMULATED, $modes, true);

        // live
        $live = ['available' => false, 'reason' => 'live_unavailable'];
        if ((bool) config('connector.patch.live_ready', false)) {
            $cap = $this->patchCapability((int) $env->id);
            if (! $cap || ($cap['name'] ?? null) !== 'rpo_patch') {
                $live = ['available' => false, 'reason' => 'patch_capability_absent'];
            } else {
                $supported = collect((array) config('connector.patch.supported_capabilities', []))
                    ->contains(fn ($c) => ($c['name'] ?? null) === 'rpo_patch' && (int) ($c['contract_version'] ?? -1) === (int) ($cap['contract_version'] ?? -2));
                $live = $supported ? ['available' => true, 'reason' => null] : ['available' => false, 'reason' => 'patch_contract_unsupported'];
            }
        }
        return [
            'fixture' => ['available' => $fixtureOk, 'reason' => $fixtureOk ? null : 'fixture_disabled'],
            'simulated' => ['available' => $simOk, 'reason' => $simOk ? null : 'simulated_not_executable'],
            'live' => $live,
            'capability_declared' => (bool) $this->patchCapability((int) $env->id),
        ];
    }

    /** Modo que a REQUEST pode pedir (fail-closed). */
    private function modeRequestable(string $mode): bool
    {
        if ($mode === PatchRequest::MODE_FIXTURE) {
            return (bool) config('connector.patch.allow_fixture', false);
        }
        return in_array($mode, (array) config('connector.patch.executable_modes', ['simulated']), true);
    }

    // ── PatchInput: SÓ metadados seguros. Zero bytes/path (source_ref opaco; bytes on-prem). ──
    public function createInput(EnvEnvironment $env, array $data, int $userId): array
    {
        if (! preg_match('/^[0-9a-f]{64}$/i', (string) ($data['digest'] ?? ''))) {
            return ['ok' => false, 'error' => 'invalid_digest', 'status' => 422];
        }
        $input = PatchInput::create([
            'environment_id' => $env->id, 'customer_id' => $env->customer_id,
            'patch_id' => $this->safe((string) $data['patch_id']),
            'source_ref' => isset($data['source_ref']) ? $this->safe((string) $data['source_ref']) : null,
            'digest' => strtolower((string) $data['digest']),
            'provenance' => isset($data['provenance']) ? $this->safe((string) $data['provenance']) : null,
            'version' => $data['version'] ?? null, 'release' => $data['release'] ?? null,
            'compatibility' => is_array($data['compatibility'] ?? null) ? $data['compatibility'] : null,
            'classification' => $data['classification'] ?? null, 'created_by' => $userId,
        ]);
        return ['ok' => true, 'input' => $input];
    }

    // ── PatchRequest: base_rpo_hash CONGELADO + lote ordenado imutável + digests. NÃO executa. ──
    public function createRequest(EnvEnvironment $env, array $data, int $userId): array
    {
        $mode = (string) ($data['execution_mode'] ?? '');
        if (! in_array($mode, [PatchRequest::MODE_FIXTURE, PatchRequest::MODE_SIMULATED, PatchRequest::MODE_LIVE], true)) {
            return ['ok' => false, 'error' => 'invalid_mode', 'status' => 422];
        }
        if (! $this->modeRequestable($mode)) {
            return ['ok' => false, 'error' => 'mode_not_executable', 'status' => 422]; // fail-closed
        }
        if (! preg_match('/^[0-9a-f]{64}$/i', (string) ($data['base_rpo_hash'] ?? ''))) {
            return ['ok' => false, 'error' => 'invalid_base_rpo_hash', 'status' => 422];
        }
        $order = array_values((array) ($data['patch_input_ids'] ?? []));
        if (! $order) {
            return ['ok' => false, 'error' => 'empty_batch', 'status' => 422];
        }
        if (count($order) !== count(array_unique($order))) {
            return ['ok' => false, 'error' => 'duplicate_in_batch', 'status' => 422];
        }
        // Todos os inputs pertencem ao ambiente (anti-IDOR) — pin do digest no momento da request.
        $inputs = PatchInput::where('environment_id', $env->id)->whereIn('id', $order)->get()->keyBy('id');
        if ($inputs->count() !== count($order)) {
            return ['ok' => false, 'error' => 'input_not_found', 'status' => 404];
        }
        $itemDigests = array_map(fn ($id) => $inputs[$id]->digest, $order);
        $batchDigest = hash('sha256', implode('|', $itemDigests));

        $req = DB::transaction(function () use ($env, $data, $mode, $order, $inputs, $batchDigest, $userId) {
            $r = PatchRequest::create([
                'environment_id' => $env->id, 'customer_id' => $env->customer_id,
                'base_rpo_hash' => strtolower((string) $data['base_rpo_hash']),
                'execution_mode' => $mode, 'workspace_unit_id' => isset($data['workspace_unit_id']) ? $this->safe((string) $data['workspace_unit_id']) : null,
                'batch_digest' => $batchDigest, 'classification' => $data['classification'] ?? null,
                'status' => PatchRequest::ST_OPEN, 'correlation_id' => (string) Str::uuid(),
                'requested_by' => $userId, 'requested_at' => now(),
            ]);
            foreach ($order as $i => $inputId) {
                PatchRequestItem::create([
                    'patch_request_id' => $r->id, 'patch_input_id' => $inputId,
                    'batch_order' => $i + 1, 'item_digest' => $inputs[$inputId]->digest,
                ]);
            }
            return $r;
        });
        return ['ok' => true, 'request' => $req];
    }
}
