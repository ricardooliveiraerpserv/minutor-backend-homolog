<?php

namespace App\Connector\BaseSeed;

/**
 * CP-PREPHYSICAL — CONTRATO físico de preparação de base (interface versionada). Resolve o requisito descoberto
 * no PATCH-0: o workspace deve começar EXATAMENTE no RPO-base aprovado antes de Compile/Patch. O mecanismo real é
 * on-prem/TOTVS homologado — aqui só o CONTRATO. NENHUM path/byte/INI cruza para o Minutor; só o veredito + digests.
 *
 * BasePreparationResult (retorno SANITIZADO, sem bytes/path/secret):
 *   [
 *     'workspace_unit_id'     => <opaco>,
 *     'approved_digest'       => <sha256 da base aprovada>,
 *     'observed_local_digest' => <sha256 provado localmente> | null,
 *     'result'                => 'prepared' | 'reseeded' | 'base_mismatch' | 'unavailable',
 *     'adapter_version'       => <string>,
 *     'simulated'             => bool,   // true enquanto não houver adapter TOTVS homologado
 *   ]
 */
interface BaseSeeder
{
    /** simulated | live */
    public function mode(): string;

    /** Deny-by-default. live = unavailable enquanto o mecanismo TOTVS não estiver homologado. */
    public function availability(int $envId): array; // {available: bool, reason: ?string}

    /** Prepara o workspace no RPO-base aprovado e PROVA localmente (digest == approved). */
    public function prepareBase(int $envId, string $workspaceUnitId, string $approvedBaseDigest): array;

    /** Recuperação: re-seed do workspace ao RPO-base aprovado (após partial/indeterminate). */
    public function reseedBase(int $envId, string $workspaceUnitId, string $approvedBaseDigest): array;
}
