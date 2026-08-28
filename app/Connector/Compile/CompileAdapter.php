<?php

namespace App\Connector\Compile;

use App\Models\CompileExecution;
use App\Models\CompileRequest;

/**
 * C6 — contrato do adapter de compilação. Isolamento por contrato: o modo não comprovado (live) NUNCA se
 * apresenta como operacional real, e NÃO existe fallback silencioso entre modos. Compile PRODUZ artefato;
 * jamais publica RPO. Nenhuma implementação pode retornar "fake success".
 *
 * CompileOutcome (retorno de compile()) — array SANITIZADO (sem bytes/path/secret/log bruto):
 *   [
 *     'outcome'     => 'succeeded' | 'failed' | 'timed_out' | 'unknown',
 *     'artifact'    => null | [                    // presente SÓ quando outcome = succeeded
 *        'digest'      => <sha256>,                 // ArtifactIdentity (calculado on-prem/adapter)
 *        'unit'        => 'standalone'|'rpo_apo_full'|'rpo_apo_incremental'|'unknown',
 *        'size_bytes'  => ?int,                     // se seguro
 *        'metadata'    => array,                    // SAFE (ex.: warnings=0, target)
 *     ],
 *     'context'     => array,                       // fatores OBSERVADOS (compiler/version/flags/includes…)
 *     'diagnostics' => array,                       // SAFE only (contagens/mensagens classificadas)
 *     'error'       => ?string,                     // motivo sanitizado em não-sucesso (ex.: live_unavailable)
 *   ]
 */
interface CompileAdapter
{
    /** fixture | simulated | live */
    public function mode(): string;

    /**
     * Disponibilidade DENY-BY-DEFAULT para esta request. live retorna unavailable enquanto o mecanismo
     * TOTVS não estiver comprovado/configurado (validação física = gate final separado).
     * @return array{available: bool, reason: ?string}
     */
    public function availability(CompileRequest $request): array;

    /** Executa a compilação e retorna o CompileOutcome. NUNCA "fake success". */
    public function compile(CompileRequest $request, CompileExecution $execution): array;
}
