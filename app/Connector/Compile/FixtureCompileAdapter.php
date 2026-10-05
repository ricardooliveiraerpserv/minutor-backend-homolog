<?php

namespace App\Connector\Compile;

use App\Models\CompileExecution;
use App\Models\CompileRequest;

/**
 * C6 — FixtureCompileAdapter: SOMENTE dev/test. Resultado determinístico para exercitar contrato/state
 * machine/UX/auditoria. NUNCA habilitado em homolog/prod por fallback (gate explícito
 * connector.compile.allow_fixture, default false). Sempre marcado como fixture.
 */
class FixtureCompileAdapter implements CompileAdapter
{
    public function mode(): string
    {
        return CompileRequest::MODE_FIXTURE;
    }

    public function availability(CompileRequest $request): array
    {
        $allowed = (bool) config('connector.compile.allow_fixture', false);
        return $allowed
            ? ['available' => true, 'reason' => null]
            : ['available' => false, 'reason' => 'fixture_disabled'];
    }

    public function compile(CompileRequest $request, CompileExecution $execution): array
    {
        // Digest FAKE derivado da entrada — estável para testes. O sistema NÃO deduplica por digest.
        $digest = hash('sha256', 'fixture|' . $request->source_blob_sha . '|' . $request->language . '|' . (string) $request->target);
        return [
            'outcome' => CompileExecution::ST_SUCCEEDED,
            'artifact' => [
                'digest' => $digest,
                'unit' => \App\Models\ArtifactCandidate::UNIT_STANDALONE,
                'size_bytes' => 1024,
                'metadata' => ['fixture' => true, 'warnings' => 0, 'language' => $request->language],
            ],
            'context' => [
                'compiler_identity' => 'fixture-compiler',
                'compiler_version' => '0.0.0-fixture',
                'factors' => ['fixture' => true],
            ],
            'diagnostics' => ['level' => 'SAFE', 'errors' => 0, 'warnings' => 0, 'messages' => []],
            'error' => null,
        ];
    }
}
