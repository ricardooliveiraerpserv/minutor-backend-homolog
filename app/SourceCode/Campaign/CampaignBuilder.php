<?php

namespace App\SourceCode\Campaign;

use App\Models\SourceSemanticCampaign;
use Illuminate\Support\Facades\DB;

/**
 * Monta os itens da campanha (orientado a BLOB): 1 representante por blob (fase 1 = IA) + réplicas
 * (fase 2 = reuso a US$ 0). Gigantes EXCLUÍDOS (excluded_giant). Estimativa de custo por faixa.
 *
 * Estimativas por faixa são PRELIMINARES (ajuste #3): médio a US$ 0,20 (não US$ 0,14 — ainda não
 * validado; REIA550C pós-correção custou ~US$ 0,26). Recalibrar após o piloto.
 */
class CampaignBuilder
{
    /** Custo estimado por faixa (preliminar — recalibrar com o piloto). */
    public function estCost(string $band): float
    {
        return match ($band) {
            'pequeno' => (float) config('services.source_doc_ai.est_pequeno_usd', 0.05),
            'medio'   => (float) config('services.source_doc_ai.est_medio_usd', 0.20),
            'grande'  => (float) config('services.source_doc_ai.est_grande_usd', 0.28),
            default   => 0.0, // gigante: fora
        };
    }

    /**
     * Cria os itens. $sourceDocIds = null ⇒ acervo inteiro (exceto gigantes); array ⇒ subconjunto (piloto).
     * Retorna [unique_blobs, total_candidates, giants_excluded, estimated_total_usd].
     */
    public function build(SourceSemanticCampaign $campaign, ?array $sourceDocIds = null): array
    {
        $bandExpr = "case
            when linhas > 9800 or funcs > 98 then 'gigante'
            when linhas > 1200 or funcs > 16 then 'grande'
            when linhas > 150  or funcs > 3  then 'medio'
            else 'pequeno' end";

        $filter = $sourceDocIds !== null
            ? 'and sd.id in (' . implode(',', array_map('intval', $sourceDocIds)) . ')'
            : '';

        // base de métricas por doc (band + blob) — usa o determinístico já armazenado.
        $rows = DB::select("
            with m as (
                select sd.id,
                    cv.source_blob_sha as blob,
                    coalesce((select max((f->>'end_line')::int) from json_array_elements(sd.documentation_json::json->'deterministic'->'functions') f),0) as linhas,
                    coalesce(json_array_length(sd.documentation_json::json->'deterministic'->'functions'),0) as funcs
                from source_docs sd
                join source_doc_versions cv on cv.id = sd.current_version_id
                where sd.current_version_id is not null $filter
            )
            select id, blob, $bandExpr as band,
                (row_number() over (partition by blob order by id) = 1) as is_rep
            from m
            order by band, blob, id
        ");

        $now = now();
        $insert = [];
        $uniqueBlobs = [];
        $giants = 0;
        $est = 0.0;
        foreach ($rows as $r) {
            $band = $r->band;
            $isRep = (bool) $r->is_rep;
            $isGiant = $band === 'gigante';
            if ($isRep && ! $isGiant) {
                $uniqueBlobs[$r->blob] = true;
                $est += $this->estCost($band);
            }
            if ($isGiant) {
                $giants++;
            }
            $insert[] = [
                'campaign_id'        => $campaign->id,
                'source_doc_id'      => $r->id,
                'blob_sha'           => $r->blob,
                'band'               => $band,
                'is_representative'  => $isRep,
                'phase'             => $isRep ? 1 : 2,
                'status'            => $isGiant ? 'excluded_giant' : 'pending',
                'estimated_cost_usd' => ($isRep && ! $isGiant) ? $this->estCost($band) : 0.0,
                'created_at'         => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('source_semantic_campaign_items')->insert($chunk);
        }

        $campaign->update([
            'total_candidates'    => count(array_filter($insert, fn ($i) => $i['status'] !== 'excluded_giant')),
            'unique_blobs'        => count($uniqueBlobs),
            'giants_excluded'     => $giants,
            'estimated_total_usd' => round($est, 4),
            'status'              => 'ready',
        ]);

        return [
            'unique_blobs' => count($uniqueBlobs),
            'total_candidates' => count(array_filter($insert, fn ($i) => $i['status'] !== 'excluded_giant')),
            'giants_excluded' => $giants,
            'estimated_total_usd' => round($est, 4),
        ];
    }
}
