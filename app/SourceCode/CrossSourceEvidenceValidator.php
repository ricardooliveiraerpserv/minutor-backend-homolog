<?php

namespace App\SourceCode;

use App\Models\SourceDoc;
use Illuminate\Support\Facades\DB;

/**
 * Cross-source Fase 2 — juiz DETERMINÍSTICO de evidência que aponta para OUTRO source_doc. A IA NUNCA
 * é autoridade para dizer que uma evidência externa existe: só é aceita (level C) se TODAS as verificações
 * determinísticas passarem. Não afrouxa o anti-alucinação local; é a rota cross-source do mesmo juiz.
 *
 * P0 — BLOB: a evidência fica vinculada à VERSÃO EXATA do alvo que forneceu os facts. Se o blob do alvo
 * mudou (GMUD), a evidência antiga é 'blob_stale' e NÃO é aceita silenciosamente como atual.
 */
class CrossSourceEvidenceValidator
{
    /** @return array{accepted:bool,reason:string,evidence:?array} */
    public function validate(array $ev, ?int $dependentDocId): array
    {
        $targetId = isset($ev['source_doc_id']) ? (int) $ev['source_doc_id'] : 0;
        $symbol   = $this->norm((string) ($ev['symbol'] ?? ''));
        $relation = (string) ($ev['relation'] ?? '');
        $blob     = (string) ($ev['blob_sha'] ?? '');
        $type     = strtolower((string) ($ev['evidence_type'] ?? $ev['type'] ?? 'function'));

        if ($targetId <= 0 || $symbol === '' || $relation === '' || $blob === '') {
            return $this->reject('incomplete_cross_evidence');
        }
        if (! $dependentDocId) {
            return $this->reject('no_dependent_context'); // sem o dependente não há como consultar o grafo
        }

        // (1,2,3,6) relação origem→alvo existe, RESOLVED, alvo confere, relation compatível — via edge da Fase 1.
        $edges = DB::table('source_semantic_context_edge')
            ->where('dependent_source_doc_id', $dependentDocId)
            ->where('symbol', $symbol)
            ->get();
        if ($edges->isEmpty()) {
            return $this->reject('no_edge_for_symbol');
        }
        $edge = $edges->first(fn ($e) => $e->state === 'resolved' && (int) $e->target_source_doc_id === $targetId);
        if (! $edge) {
            // há edge p/ o símbolo, mas não RESOLVED p/ este alvo → informa o motivo real (ambiguous/unresolved/alvo).
            $any = $edges->first();
            if ($any->state !== 'resolved') {
                return $this->reject('edge_' . $any->state); // ambiguous/unresolved NUNCA sustenta level C
            }
            return $this->reject('target_mismatch'); // resolvido, porém para OUTRO alvo
        }
        if ($relation !== $edge->relation) {
            return $this->reject('relation_incompatible');
        }

        // (4) P0 — blob confere com a versão ATUAL do alvo (facts usados)?
        $doc = SourceDoc::with('currentVersion')->find($targetId);
        $ver = $doc?->currentVersion;
        if (! $doc || ! $ver) {
            return $this->reject('target_not_found');
        }
        if ((string) $ver->source_blob_sha !== $blob) {
            return $this->reject('blob_stale'); // evidência contra versão diferente da atual → não aceita
        }
        // e o blob da edge tem que ser o mesmo que a evidência declara (coerência do grafo).
        if ($edge->target_blob_sha !== null && (string) $edge->target_blob_sha !== $blob) {
            return $this->reject('edge_blob_mismatch');
        }

        // (5) símbolo/tabela/campo referenciado EXISTE deterministicamente nos facts do alvo.
        $det = is_array($ver->deterministic_json) ? $ver->deterministic_json : [];
        if (! $this->existsInTarget($det, $type, $ev, $symbol)) {
            return $this->reject('symbol_not_in_target:' . $type);
        }

        return [
            'accepted' => true,
            'reason' => 'ok',
            'evidence' => [
                'level' => 'C', 'source_doc_id' => $targetId, 'blob_sha' => $blob,
                'symbol' => $ev['symbol'] ?? $symbol, 'relation' => $relation, 'evidence_type' => $type,
                'start_line' => $ev['start_line'] ?? null,
            ],
        ];
    }

    private function existsInTarget(array $det, string $type, array $ev, string $symbolNorm): bool
    {
        if ($type === 'function' || $type === '' || $type === 'calls_user') {
            foreach (($det['functions'] ?? []) as $f) {
                if ($this->norm((string) ($f['name'] ?? '')) === $symbolNorm) {
                    return true;
                }
            }
            return false;
        }
        if ($type === 'table') {
            $want = strtoupper((string) ($ev['table'] ?? $ev['symbol'] ?? ''));
            foreach (($det['tables'] ?? []) as $t) {
                if (strtoupper((string) ($t['table'] ?? $t['alias'] ?? '')) === $want) {
                    return true;
                }
            }
            return false;
        }
        if ($type === 'field') {
            $tab = strtoupper((string) ($ev['table'] ?? ''));
            $fld = strtoupper((string) ($ev['field'] ?? ''));
            foreach (($det['tables'] ?? []) as $t) {
                $tt = strtoupper((string) ($t['table'] ?? $t['alias'] ?? ''));
                if ($tab !== '' && $tt !== $tab) {
                    continue;
                }
                foreach (['read_fields', 'write_fields', 'where_fields', 'fields'] as $k) {
                    foreach ((array) ($t[$k] ?? []) as $c) {
                        if (strtoupper((string) $c) === $fld) {
                            return true;
                        }
                    }
                }
            }
            return false;
        }
        return false;
    }

    private function reject(string $reason): array
    {
        return ['accepted' => false, 'reason' => $reason, 'evidence' => null];
    }

    private function norm(string $s): string
    {
        $s = strtolower(trim($s));
        return str_starts_with($s, 'u_') ? substr($s, 2) : $s;
    }
}
