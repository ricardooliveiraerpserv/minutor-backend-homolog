<?php

namespace App\Connector\Compile;

use App\Models\ArtifactCandidate;
use App\Models\CompileExecution;
use App\Models\CompileRequest;

/**
 * C6 — SimulatedCompileAdapter: homolog CONTROLADA. Produz resultados determinísticos/configuráveis para
 * validar contrato, state machine, UX, auditoria e handoff ao C5 — SEM mecanismo TOTVS real. É SEMPRE
 * marcado como 'simulated' (nunca se apresenta como live). O outcome pode ser configurado
 * (connector.compile.simulated_outcome) para exercitar failed/timed_out/unknown no gate — nunca fake live.
 */
class SimulatedCompileAdapter implements CompileAdapter
{
    public function mode(): string
    {
        return CompileRequest::MODE_SIMULATED;
    }

    public function availability(CompileRequest $request): array
    {
        // Homolog controlada: disponível quando 'simulated' está na allowlist de modos executáveis.
        $modes = (array) config('connector.compile.executable_modes', ['simulated']);
        return in_array(CompileRequest::MODE_SIMULATED, $modes, true)
            ? ['available' => true, 'reason' => null]
            : ['available' => false, 'reason' => 'simulated_not_executable'];
    }

    public function compile(CompileRequest $request, CompileExecution $execution): array
    {
        $forced = (string) config('connector.compile.simulated_outcome', CompileExecution::ST_SUCCEEDED);

        // Contexto observado simulado (fatores plausíveis) — SANITIZADO, sem secret/path.
        $context = [
            'compiler_identity' => 'simulated-appserver',
            'compiler_version' => '12.1.2410',
            'compiler_build' => 'sim',
            'target_runtime' => $request->target ?: 'appserver',
            'factors' => ['language' => $request->language, 'includes' => [], 'flags' => []],
        ];

        if ($forced === CompileExecution::ST_FAILED) {
            return [
                'outcome' => CompileExecution::ST_FAILED,
                'artifact' => null, // failed NUNCA gera artifact
                'context' => $context,
                'diagnostics' => ['level' => 'SAFE', 'errors' => 1, 'warnings' => 0, 'messages' => ['E: erro de compilação simulado']],
                'error' => 'compile_failed',
            ];
        }
        if ($forced === CompileExecution::ST_TIMED_OUT) {
            return ['outcome' => CompileExecution::ST_TIMED_OUT, 'artifact' => null, 'context' => $context, 'diagnostics' => ['level' => 'SAFE', 'note' => 'deadline simulado'], 'error' => 'timeout'];
        }
        if ($forced === CompileExecution::ST_UNKNOWN) {
            return ['outcome' => CompileExecution::ST_UNKNOWN, 'artifact' => null, 'context' => $context, 'diagnostics' => ['level' => 'SAFE', 'note' => 'comunicação perdida simulada'], 'error' => 'indeterminate'];
        }

        // succeeded — digest content-derivado (determinístico para mesma entrada+contexto). O sistema NÃO
        // deduplica por digest; determinismo NÃO é assumido pelo produto.
        $digest = hash('sha256', 'simulated|' . $request->source_blob_sha . '|' . $request->language . '|' . ($request->target ?: 'appserver') . '|' . ($context['compiler_version']));
        return [
            'outcome' => CompileExecution::ST_SUCCEEDED,
            'artifact' => [
                'digest' => $digest,
                'unit' => ArtifactCandidate::UNIT_STANDALONE, // fronteira ainda a comprovar (C6.1 Ajuste A)
                'size_bytes' => 4096,
                'metadata' => ['simulated' => true, 'warnings' => 0, 'target' => $request->target ?: 'appserver', 'language' => $request->language],
            ],
            'context' => $context,
            'diagnostics' => ['level' => 'SAFE', 'errors' => 0, 'warnings' => 0, 'messages' => []],
            'error' => null,
        ];
    }
}
