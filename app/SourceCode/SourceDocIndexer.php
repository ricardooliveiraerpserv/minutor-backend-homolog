<?php

namespace App\SourceCode;

use App\Models\SourceDoc;
use App\Models\SourceDocEntity;
use App\Models\SourceDocIndex;
use Illuminate\Support\Facades\DB;

/**
 * Central de Fontes — C2. Constrói o READ-MODEL derivado (source_doc_index + source_doc_entities)
 * a partir do deterministic_json da versão vigente. Descartável e reconstruível a qualquer momento;
 * NÃO é fonte de verdade e NUNCA materializa a situação Git (essa é do SourceDocStatusResolver).
 *
 * Consistência (stale): INDEX_VALID = indexed_version_id == current_version_id
 *                                  AND indexed_blob_sha == currentVersion.source_blob_sha.
 * indexed_at é só auditoria.
 */
class SourceDocIndexer
{
    /** Regra formal de validade do índice de um fonte. */
    public function isStale(SourceDoc $doc): bool
    {
        $idx = SourceDocIndex::find($doc->id);
        if (! $idx) {
            return true;
        }
        $ver = $doc->relationLoaded('currentVersion') ? $doc->currentVersion : $doc->currentVersion()->first();
        if (! $ver) {
            return true; // sem versão vigente → não deveria estar indexado
        }

        return ! ($idx->indexed_version_id === $ver->id
            && $idx->indexed_blob_sha === $ver->source_blob_sha);
    }

    /** (Re)indexa UM fonte (delete-then-insert em transação). Idempotente. */
    public function index(SourceDoc $doc): bool
    {
        $ver = $doc->currentVersion()->first();
        if (! $ver || ! is_array($ver->deterministic_json)) {
            return false;
        }
        $det = $ver->deterministic_json;

        $entities = $this->extractEntities($det);
        $summary  = $this->summarize($det);

        DB::transaction(function () use ($doc, $ver, $entities, $summary) {
            SourceDocEntity::where('source_doc_id', $doc->id)->delete();

            $rows = array_map(fn ($e) => array_merge($e, [
                'source_doc_id'         => $doc->id,
                'source_doc_version_id' => $ver->id,
                'customer_id'           => $doc->customer_id,
                'owner'                 => $doc->owner,
                'repository'            => $doc->repository,
                'access'                => isset($e['access']) ? json_encode(array_values($e['access'])) : null,
                'risk_flags'            => isset($e['risk_flags']) ? json_encode(array_values($e['risk_flags'])) : null,
            ]), $entities);

            foreach (array_chunk($rows, 500) as $chunk) {
                SourceDocEntity::insert($chunk);
            }

            SourceDocIndex::updateOrCreate(
                ['source_doc_id' => $doc->id],
                array_merge($summary, [
                    'indexed_version_id' => $ver->id,
                    'indexed_blob_sha'   => $ver->source_blob_sha,
                    'customer_id'        => $doc->customer_id,
                    'owner'              => $doc->owner,
                    'repository'         => $doc->repository,
                    'branch'             => $doc->branch,
                    'lang'               => $doc->lang,
                    'tipo'               => $doc->tipo,
                    'analysis_status'    => $ver->analysis_status,
                    'semantic_quality'   => $this->semanticQuality($ver),
                    'indexed_at'         => now(),
                ])
            );
        });

        return true;
    }

    // ── extração ────────────────────────────────────────────────────────────

    /** @return array<int,array{entity_type:string,name:string,parent:?string,access?:array,risk_flags?:array,line_start:?int,line_end:?int}> */
    private function extractEntities(array $det): array
    {
        $out = [];
        $seen = []; // dedup por type|lower(name)|parent (merge de access)

        $add = function (string $type, ?string $name, ?string $parent = null, array $access = [], array $risk = [], ?int $ls = null, ?int $le = null) use (&$out, &$seen) {
            $name = trim((string) $name);
            if ($name === '') {
                return;
            }
            $name = mb_substr($name, 0, 300);
            $parent = $parent !== null ? mb_substr($parent, 0, 300) : null;
            $key = $type . '|' . mb_strtolower($name) . '|' . ($parent ?? '');
            if (isset($seen[$key])) {
                $i = $seen[$key];
                $out[$i]['access'] = array_values(array_unique(array_merge($out[$i]['access'] ?? [], $access)));
                $out[$i]['risk_flags'] = array_values(array_unique(array_merge($out[$i]['risk_flags'] ?? [], $risk)));
                return;
            }
            $seen[$key] = count($out);
            $out[] = [
                'entity_type' => $type, 'name' => $name, 'parent' => $parent,
                'access' => array_values($access), 'risk_flags' => array_values($risk),
                'line_start' => $ls, 'line_end' => $le,
            ];
        };

        // functions
        foreach ($this->arr($det, 'functions') as $f) {
            if (! is_array($f)) { continue; }
            $ev = $f['evidence'] ?? [];
            $add('function', $f['name'] ?? null, $f['type'] ?? null, [], [], $ev['line_start'] ?? null, $ev['line_end'] ?? null);
        }

        // tables + fields (a partir de tables[])
        foreach ($this->arr($det, 'tables') as $t) {
            if (! is_array($t)) { continue; }
            $tname = $t['table'] ?? $t['alias'] ?? null;
            $access = $this->strList($t['access'] ?? []);
            $add('table', $tname, null, $access);
            foreach ($this->strList($t['fields'] ?? []) as $field) {
                $add('field', $field, $tname, $access);
            }
        }

        // queries + fields + risk (a partir de queries[]) — também alimenta 'table'
        foreach ($this->arr($det, 'queries') as $q) {
            if (! is_array($q)) { continue; }
            $qtable = $q['table'] ?? null;
            $op = isset($q['operation']) ? [strtoupper((string) $q['operation'])] : [];
            $risk = $this->strList($q['risk_flags'] ?? []);
            $fn = $q['function'] ?? null;
            $ev = $q['evidence'] ?? [];
            $add('query', $qtable, $fn, $op, $risk, $ev['line_start'] ?? null, $ev['line_end'] ?? null);
            $add('table', $qtable, null, $op);
            foreach ($this->strList($q['fields'] ?? []) as $field) {
                $add('field', $field, $qtable, $op);
            }
            foreach ($risk as $r) {
                $add('risk', $r);
            }
        }

        // security_findings → risk
        foreach ($this->arr($det, 'security_findings') as $sf) {
            $name = is_array($sf) ? ($sf['type'] ?? null) : (is_string($sf) ? $sf : null);
            $add('risk', $name);
        }

        // integrações
        foreach (['integrations', 'external_integrations', 'endpoints'] as $k) {
            foreach ($this->arr($det, $k) as $it) {
                $name = is_array($it) ? ($it['host'] ?? $it['url'] ?? $it['name'] ?? $it['service'] ?? null) : (is_string($it) ? $it : null);
                $add('integration', $name);
            }
        }

        // dependências
        foreach (['dependencies', 'includes'] as $k) {
            foreach ($this->arr($det, $k) as $d) {
                $name = is_array($d) ? ($d['name'] ?? $d['path'] ?? null) : (is_string($d) ? $d : null);
                $add('dependency', $name);
            }
        }
        foreach ($this->arr($det, 'call_graph') as $c) {
            if (is_array($c) && ! empty($c['to'])) {
                $add('dependency', (string) $c['to'], $c['from'] ?? null);
            }
        }

        return $out;
    }

    private function summarize(array $det): array
    {
        $tables = [];
        foreach ($this->arr($det, 'tables') as $t) {
            if (is_array($t) && ! empty($t['table'])) { $tables[strtoupper($t['table'])] = true; }
        }
        $risk = [];
        foreach ($this->arr($det, 'queries') as $q) {
            foreach ($this->strList(is_array($q) ? ($q['risk_flags'] ?? []) : []) as $r) { $risk[$r] = true; }
        }
        foreach ($this->arr($det, 'security_findings') as $sf) {
            $t = is_array($sf) ? ($sf['type'] ?? null) : (is_string($sf) ? $sf : null);
            if ($t) { $risk[$t] = true; }
        }
        $integr = [];
        foreach (['integrations', 'external_integrations', 'endpoints'] as $k) {
            foreach ($this->arr($det, $k) as $it) {
                $n = is_array($it) ? ($it['host'] ?? $it['url'] ?? $it['name'] ?? $it['service'] ?? null) : (is_string($it) ? $it : null);
                if ($n) { $integr[$n] = true; }
            }
        }

        return [
            'functions_count' => count($this->arr($det, 'functions')),
            'tables_count'    => count($tables),
            'queries_count'   => count($this->arr($det, 'queries')),
            'has_risk'        => ! empty($risk),
            'risk_flags'      => array_keys($risk),
            'integrations'    => array_keys($integr),
        ];
    }

    private function semanticQuality($ver): string
    {
        if (empty($ver->semantic_json)) {
            return 'none';
        }
        return $ver->analysis_status === 'completed' ? 'completed' : 'partial';
    }

    private function arr(array $det, string $key): array
    {
        return isset($det[$key]) && is_array($det[$key]) ? $det[$key] : [];
    }

    /** Normaliza uma lista para array de strings não-vazias. */
    private function strList($v): array
    {
        if (! is_array($v)) { return []; }
        $out = [];
        foreach ($v as $x) {
            if (is_string($x) && trim($x) !== '') { $out[] = trim($x); }
        }
        return array_values(array_unique($out));
    }
}
