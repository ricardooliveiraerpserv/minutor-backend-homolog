<?php

namespace App\Connector\Compile;

use App\Models\CompileExecution;
use App\Models\CompileRequest;
use App\Models\ConnectorEnvironmentState;

/**
 * C6 — LiveCompileAdapter: CONTRATO pronto, mecanismo físico TOTVS PENDENTE. Enquanto a validação física
 * (protocolo C6.1 on-prem) não for concluída e o ambiente não declarar a capability source_compile suportada
 * E o live não for explicitamente habilitado, availability() retorna unavailable. NENHUM fake success,
 * NENHUM fallback silencioso. Se compile() for chamado sem disponibilidade real, devolve unknown/unavailable
 * (o serviço bloqueia antes; isto é apenas defesa em profundidade).
 */
class LiveCompileAdapter implements CompileAdapter
{
    public function mode(): string
    {
        return CompileRequest::MODE_LIVE;
    }

    public function availability(CompileRequest $request): array
    {
        // Gate 1: live explicitamente pronto (só após validação física comprovada). Default false.
        if (! (bool) config('connector.compile.live_ready', false)) {
            return ['available' => false, 'reason' => 'live_unavailable']; // "Compilação real aguardando conector TOTVS"
        }
        // Gate 2: o ambiente precisa DECLARAR a capability source_compile suportada (fail-closed).
        $state = ConnectorEnvironmentState::where('environment_id', $request->environment_id)->first();
        $cap = is_array($state?->compile_capability ?? null) ? $state->compile_capability : null;
        if (! $cap || ($cap['name'] ?? null) !== 'source_compile') {
            return ['available' => false, 'reason' => 'compile_capability_absent'];
        }
        $supported = collect((array) config('connector.compile.supported_capabilities', []))
            ->contains(fn ($c) => ($c['name'] ?? null) === 'source_compile' && (int) ($c['contract_version'] ?? -1) === (int) ($cap['contract_version'] ?? -2));
        if (! $supported) {
            return ['available' => false, 'reason' => 'compile_contract_unsupported'];
        }
        return ['available' => true, 'reason' => null];
    }

    public function compile(CompileRequest $request, CompileExecution $execution): array
    {
        // Defesa em profundidade — o serviço NUNCA deve chegar aqui sem availability(). Sem fake success.
        return [
            'outcome' => CompileExecution::ST_UNKNOWN,
            'artifact' => null,
            'context' => [],
            'diagnostics' => ['level' => 'SAFE', 'note' => 'live indisponível'],
            'error' => 'live_unavailable',
        ];
    }
}
