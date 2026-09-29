<?php

namespace App\SourceCode\Analyzer;

/**
 * Diff ESTRUTURAL-SEMÂNTICO (determinístico) entre a versão anterior e a atual de um fonte.
 * Compara as saídas do AdvplAnalyzer (deterministic_json) — não só linhas de texto — e produz
 * FATOS técnicos: funções/tabelas/campos/SQL/dependências/integrações/efeitos/chamadas
 * adicionados/removidos/alterados, com evidência por linha (da versão nova p/ adição/alteração;
 * da versão anterior p/ remoção). O diff de linhas continua como evidência complementar.
 *
 * NÃO interpreta negócio (isso é a camada semântica). Ex.: pode dizer "STATUSMAIL passou a ser
 * atualizado em SPED050"; NÃO diz "agora o sistema envia e-mail".
 *
 * change_type: 'initial' (sem versão anterior) | 'modified'. structural_change=false quando houve
 * commit mas nenhuma mudança estrutural relevante (só comentário/formatação/espaçamento) — mesmo
 * com lines_added/removed > 0.
 *
 * Saída ADITIVA: mantém as chaves antigas (is_creation, functions_added/removed/changed [nomes],
 * tables_added/removed, diff_stats.added_lines/removed_lines/…) e adiciona a estrutura rica.
 */
class SourceDiff
{
    /** Teto de células do LCS de linhas — acima disso usa aproximação por multiconjunto (não quadrático). */
    private const LCS_CELL_CAP = 3_000_000;

    /**
     * @param array|null $old deterministic_json anterior (null = criação/primeira versão)
     * @param array      $new deterministic_json atual
     */
    public function compare(?array $old, array $new, ?string $oldCode, string $newCode): array
    {
        $isCreation = $old === null;
        $old ??= [];

        $fnDiff   = $this->diffFunctions($old, $new);
        $tblDiff  = $this->diffTables($old, $new);
        $fldDiff  = $this->diffFields($old, $new);
        $sqlDiff  = $this->diffSql($old, $new);
        $depDiff  = $this->diffDependencies($old, $new);
        $intDiff  = $this->diffIntegrations($old, $new);
        $effDiff  = $this->diffEffects($old, $new);
        $callDiff = $this->diffCalls($old, $new);
        $line     = $this->lineStats($oldCode ?? '', $newCode);

        // structural_change = existe QUALQUER delta estrutural (ignora linhas/comentário/formatação).
        $structural = $isCreation || (
            $fnDiff['n'] || $tblDiff['n'] || $fldDiff['n'] || $sqlDiff['n']
            || $depDiff['n'] || $intDiff['n'] || $effDiff['n'] || $callDiff['n']
        );

        $diffStats = [
            // ── compat (chaves antigas) ──
            'added_lines'         => $line['added'],
            'removed_lines'       => $line['removed'],
            'functions_added'     => count($fnDiff['added']),
            'functions_removed'   => count($fnDiff['removed']),
            'functions_changed'   => count($fnDiff['changed']),
            'tables_added'        => count($tblDiff['added']),
            'tables_removed'      => count($tblDiff['removed']),
            // ── novas (Bloco 2) ──
            'lines_added'             => $line['added'],
            'lines_removed'           => $line['removed'],
            'tables_changed'          => count($tblDiff['changed']),
            'fields_added'            => count($fldDiff['added']),
            'fields_removed'          => count($fldDiff['removed']),
            'fields_changed'          => count($fldDiff['changed']),
            'sql_operations_added'    => count($sqlDiff['added']),
            'sql_operations_removed'  => count($sqlDiff['removed']),
            'sql_operations_changed'  => count($sqlDiff['changed']),
            'dependencies_added'      => $depDiff['added_count'],
            'dependencies_removed'    => $depDiff['removed_count'],
            'integrations_added'      => count($intDiff['added']),
            'integrations_removed'    => count($intDiff['removed']),
            'integrations_changed'    => count($intDiff['changed']),
            'effects_added'           => count($effDiff['added']),
            'effects_removed'         => count($effDiff['removed']),
            'effects_changed'         => count($effDiff['changed']),
            'calls_added'             => count($callDiff['added']),
            'calls_removed'           => count($callDiff['removed']),
            // ── meta ──
            'change_type'         => $isCreation ? 'initial' : 'modified',
            'structural_change'   => $structural,
            'lines_approx'        => $line['approx'] ?? false,
        ];

        return [
            // ── compat ──
            'is_creation'       => $isCreation,
            'functions_added'   => array_column($fnDiff['added'], 'name'),
            'functions_removed' => array_column($fnDiff['removed'], 'name'),
            'functions_changed' => array_column($fnDiff['changed'], 'function'),
            'tables_added'      => array_column($tblDiff['added'], 'table'),
            'tables_removed'    => array_column($tblDiff['removed'], 'table'),
            // ── meta ──
            'change_type'       => $isCreation ? 'initial' : 'modified',
            'structural_change' => $structural,
            // ── estrutura rica ──
            'structural'        => [
                'functions'    => ['added' => $fnDiff['added'], 'removed' => $fnDiff['removed'], 'changed' => $fnDiff['changed']],
                'tables'       => ['added' => $tblDiff['added'], 'removed' => $tblDiff['removed'], 'changed' => $tblDiff['changed']],
                'fields'       => ['added' => $fldDiff['added'], 'removed' => $fldDiff['removed'], 'changed' => $fldDiff['changed']],
                'sql'          => ['added' => $sqlDiff['added'], 'removed' => $sqlDiff['removed'], 'changed' => $sqlDiff['changed']],
                'dependencies' => $depDiff['detail'],
                'integrations' => ['added' => $intDiff['added'], 'removed' => $intDiff['removed'], 'changed' => $intDiff['changed']],
                'effects'      => ['added' => $effDiff['added'], 'removed' => $effDiff['removed'], 'changed' => $effDiff['changed']],
                'call_graph'   => ['calls_added' => $callDiff['added'], 'calls_removed' => $callDiff['removed']],
            ],
            'diff_stats'        => $diffStats,
        ];
    }

    // ── funções ───────────────────────────────────────────────────────────────
    private function diffFunctions(array $old, array $new): array
    {
        $oldFns = $this->byName($old['functions'] ?? []);
        $newFns = $this->byName($new['functions'] ?? []);
        $oldQ = $this->queriesByFunction($old);
        $newQ = $this->queriesByFunction($new);

        $added = $removed = $changed = [];
        foreach (array_diff(array_keys($newFns), array_keys($oldFns)) as $k) {
            $f = $newFns[$k];
            $added[] = ['name' => $f['name'], 'type' => $f['type'] ?? null, 'evidence' => $f['evidence'] ?? null];
        }
        foreach (array_diff(array_keys($oldFns), array_keys($newFns)) as $k) {
            $f = $oldFns[$k];
            $removed[] = ['name' => $f['name'], 'type' => $f['type'] ?? null, 'evidence' => $f['evidence'] ?? null];
        }
        foreach (array_intersect(array_keys($newFns), array_keys($oldFns)) as $k) {
            $of = $oldFns[$k];
            $nf = $newFns[$k];
            $changes = [];
            $this->putListDelta($changes, 'tables', $of['tables'] ?? [], $nf['tables'] ?? []);
            $this->putListDelta($changes, 'accesses', $of['accesses'] ?? [], $nf['accesses'] ?? []);
            $this->putListDelta($changes, 'calls', $this->normCalls($of), $this->normCalls($nf));
            $this->putListDelta($changes, 'effects', $of['effects'] ?? [], $nf['effects'] ?? []);
            // campos por papel derivados das queries atribuídas à função
            foreach (['read_fields', 'write_fields', 'where_fields'] as $role) {
                $this->putListDelta($changes, $role, $this->fieldsFromQueries($oldQ[$k] ?? [], $role), $this->fieldsFromQueries($newQ[$k] ?? [], $role));
            }
            // assinatura (params/returns)
            if (array_map('strtolower', $of['params'] ?? []) !== array_map('strtolower', $nf['params'] ?? [])) {
                $changes['params'] = ['before' => $of['params'] ?? [], 'after' => $nf['params'] ?? []];
            }
            if (($of['returns'] ?? []) !== ($nf['returns'] ?? [])) {
                $changes['returns_changed'] = true;
            }
            if (!empty($changes)) {
                $changed[] = ['function' => $nf['name'], 'changes' => $changes, 'evidence' => $nf['evidence'] ?? null];
            }
        }
        return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'n' => count($added) + count($removed) + count($changed)];
    }

    // ── tabelas ───────────────────────────────────────────────────────────────
    private function diffTables(array $old, array $new): array
    {
        $oldT = $this->tablesByAlias($old);
        $newT = $this->tablesByAlias($new);
        $added = $removed = $changed = [];
        foreach (array_diff(array_keys($newT), array_keys($oldT)) as $k) {
            $t = $newT[$k];
            $added[] = ['table' => $t['table'] ?? $k, 'access' => $t['access'] ?? [], 'source' => $t['source'] ?? [], 'evidence' => $t['evidence'] ?? null];
        }
        foreach (array_diff(array_keys($oldT), array_keys($newT)) as $k) {
            $t = $oldT[$k];
            $removed[] = ['table' => $t['table'] ?? $k, 'access' => $t['access'] ?? [], 'source' => $t['source'] ?? [], 'evidence' => $t['evidence'] ?? null];
        }
        foreach (array_intersect(array_keys($newT), array_keys($oldT)) as $k) {
            $ot = $oldT[$k];
            $nt = $newT[$k];
            $c = [];
            $accBefore = $this->up($ot['access'] ?? []);
            $accAfter = $this->up($nt['access'] ?? []);
            if ($accBefore !== $accAfter) {
                $c['access_before'] = $accBefore;
                $c['access_after'] = $accAfter;
                $c['access_added'] = array_values(array_diff($accAfter, $accBefore));
                $c['access_removed'] = array_values(array_diff($accBefore, $accAfter));
            }
            foreach (['read_fields', 'write_fields', 'where_fields'] as $role) {
                $this->putListDelta($c, $role, $this->up($ot[$role] ?? []), $this->up($nt[$role] ?? []));
            }
            if (!empty($c)) {
                $changed[] = ['table' => $nt['table'] ?? $k, 'changes' => $c, 'evidence' => $nt['evidence'] ?? null];
            }
        }
        return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'n' => count($added) + count($removed) + count($changed)];
    }

    // ── campos (agregado por tabela+campo, com mudança de PAPEL read↔write↔where) ─
    private function diffFields(array $old, array $new): array
    {
        $oldR = $this->fieldRoles($old); // "TAB.FIELD" => set(role)
        $newR = $this->fieldRoles($new);
        $oldEv = $this->tableEvidence($old);
        $newEv = $this->tableEvidence($new);
        $added = $removed = $changed = [];
        foreach (array_unique(array_merge(array_keys($oldR), array_keys($newR))) as $key) {
            [$tab, $field] = explode('.', $key, 2);
            $o = $oldR[$key] ?? [];
            $n = $newR[$key] ?? [];
            sort($o);
            sort($n);
            if (!$o && $n) {
                $added[] = ['table' => $tab, 'field' => $field, 'roles' => $n, 'change' => 'added', 'evidence' => $newEv[$tab] ?? null];
            } elseif ($o && !$n) {
                $removed[] = ['table' => $tab, 'field' => $field, 'roles' => $o, 'change' => 'removed', 'evidence' => $oldEv[$tab] ?? null];
            } elseif ($o !== $n) {
                $changed[] = [
                    'table' => $tab, 'field' => $field, 'from_roles' => $o, 'to_roles' => $n,
                    'roles_added' => array_values(array_diff($n, $o)),
                    'roles_removed' => array_values(array_diff($o, $n)),
                    'change' => 'role_changed', 'evidence' => $newEv[$tab] ?? null,
                ];
            }
        }
        return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'n' => count($added) + count($removed) + count($changed)];
    }

    // ── SQL (estrutural, não por texto) ───────────────────────────────────────
    private function diffSql(array $old, array $new): array
    {
        $oldQ = $old['queries'] ?? [];
        $newQ = $new['queries'] ?? [];
        // adicionadas/removidas por assinatura completa (multiconjunto)
        $oldSig = [];
        foreach ($oldQ as $i => $q) {
            $oldSig[$this->sqlSig($q)][] = $i;
        }
        $newSig = [];
        foreach ($newQ as $i => $q) {
            $newSig[$this->sqlSig($q)][] = $i;
        }
        $addedIdx = $removedIdx = [];
        foreach ($newSig as $sig => $idxs) {
            $extra = count($idxs) - count($oldSig[$sig] ?? []);
            for ($k = 0; $k < $extra; $k++) {
                $addedIdx[] = $idxs[count($idxs) - $extra + $k];
            }
        }
        foreach ($oldSig as $sig => $idxs) {
            $extra = count($idxs) - count($newSig[$sig] ?? []);
            for ($k = 0; $k < $extra; $k++) {
                $removedIdx[] = $idxs[count($idxs) - $extra + $k];
            }
        }
        // reconcilia por (operation|table) → alteradas (mesmo alvo, campos/exec/risco diferentes)
        $groupAdd = $groupRem = [];
        foreach ($addedIdx as $i) {
            $groupAdd[$this->sqlTarget($newQ[$i])][] = $i;
        }
        foreach ($removedIdx as $i) {
            $groupRem[$this->sqlTarget($oldQ[$i])][] = $i;
        }
        $added = $removed = $changed = [];
        foreach ($groupAdd as $tgt => $idxs) {
            $rem = $groupRem[$tgt] ?? [];
            $pairs = min(count($idxs), count($rem));
            for ($p = 0; $p < $pairs; $p++) {
                $changed[] = $this->sqlChange($oldQ[$rem[$p]], $newQ[$idxs[$p]]);
            }
            for ($p = $pairs; $p < count($idxs); $p++) {
                $added[] = $this->sqlBrief($newQ[$idxs[$p]]);
            }
            $groupRem[$tgt] = array_slice($rem, $pairs);
        }
        foreach ($groupRem as $tgt => $idxs) {
            foreach ($idxs as $i) {
                $removed[] = $this->sqlBrief($oldQ[$i]);
            }
        }
        return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'n' => count($added) + count($removed) + count($changed)];
    }

    // ── dependências ──────────────────────────────────────────────────────────
    private function diffDependencies(array $old, array $new): array
    {
        $od = $old['dependencies'] ?? [];
        $nd = $new['dependencies'] ?? [];
        $cats = [
            'includes'                  => fn ($d) => $this->up($d['includes'] ?? []),
            'internal_functions'        => fn ($d) => $this->up($d['internal_functions'] ?? []),
            'custom_external_functions' => fn ($d) => $this->up(array_column($d['custom_external_functions'] ?? [], 'name')),
            'totvs_framework_functions' => fn ($d) => $d['totvs_framework_functions'] ?? [],
            'classes'                   => fn ($d) => $d['classes'] ?? [],
            'apis'                      => fn ($d) => $d['apis'] ?? [],
        ];
        $detail = [];
        $addedCount = $removedCount = 0;
        foreach ($cats as $cat => $get) {
            $o = $get($od);
            $n = $get($nd);
            $a = array_values(array_diff($n, $o));
            $r = array_values(array_diff($o, $n));
            $detail[$cat] = ['added' => $a, 'removed' => $r];
            $addedCount += count($a);
            $removedCount += count($r);
        }
        return ['detail' => $detail, 'added_count' => $addedCount, 'removed_count' => $removedCount, 'n' => $addedCount + $removedCount];
    }

    // ── integrações externas ──────────────────────────────────────────────────
    private function diffIntegrations(array $old, array $new): array
    {
        $o = [];
        foreach ($old['external_integrations'] ?? [] as $x) {
            $o[$x['type']] = $x;
        }
        $n = [];
        foreach ($new['external_integrations'] ?? [] as $x) {
            $n[$x['type']] = $x;
        }
        $added = $removed = $changed = [];
        foreach (array_diff(array_keys($n), array_keys($o)) as $t) {
            $added[] = ['type' => $t, 'evidence' => $n[$t]['evidence'] ?? null];
        }
        foreach (array_diff(array_keys($o), array_keys($n)) as $t) {
            $removed[] = ['type' => $t, 'evidence' => $o[$t]['evidence'] ?? null];
        }
        // endpoints entram/saem (informativo; só quando o tipo persiste)
        $epOld = $this->up($old['endpoints'] ?? []);
        $epNew = $this->up($new['endpoints'] ?? []);
        $epAdd = array_values(array_diff($epNew, $epOld));
        $epRem = array_values(array_diff($epOld, $epNew));
        if (($epAdd || $epRem) && array_intersect(array_keys($o), array_keys($n))) {
            $changed[] = ['type' => 'endpoints', 'endpoints_added' => $epAdd, 'endpoints_removed' => $epRem];
        }
        return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'n' => count($added) + count($removed) + count($changed)];
    }

    // ── efeitos ───────────────────────────────────────────────────────────────
    private function diffEffects(array $old, array $new): array
    {
        $oe = $old['effects'] ?? [];
        $ne = $new['effects'] ?? [];
        $oldSig = [];
        foreach ($oe as $i => $e) {
            $oldSig[$this->effectSig($e)][] = $i;
        }
        $newSig = [];
        foreach ($ne as $i => $e) {
            $newSig[$this->effectSig($e)][] = $i;
        }
        $addedIdx = $removedIdx = [];
        foreach ($newSig as $sig => $idxs) {
            $extra = count($idxs) - count($oldSig[$sig] ?? []);
            for ($k = 0; $k < $extra; $k++) {
                $addedIdx[] = $idxs[count($idxs) - $extra + $k];
            }
        }
        foreach ($oldSig as $sig => $idxs) {
            $extra = count($idxs) - count($newSig[$sig] ?? []);
            for ($k = 0; $k < $extra; $k++) {
                $removedIdx[] = $idxs[count($idxs) - $extra + $k];
            }
        }
        // reconcilia por (type|function) → alterado (mesmo tipo+função, alvo diferente)
        $ga = $gr = [];
        foreach ($addedIdx as $i) {
            $ga[strtolower(($ne[$i]['type'] ?? '') . '|' . ($ne[$i]['function'] ?? ''))][] = $i;
        }
        foreach ($removedIdx as $i) {
            $gr[strtolower(($oe[$i]['type'] ?? '') . '|' . ($oe[$i]['function'] ?? ''))][] = $i;
        }
        $added = $removed = $changed = [];
        foreach ($ga as $key => $idxs) {
            $rem = $gr[$key] ?? [];
            $pairs = min(count($idxs), count($rem));
            for ($p = 0; $p < $pairs; $p++) {
                $ov = $oe[$rem[$p]];
                $nv = $ne[$idxs[$p]];
                $changed[] = ['type' => $nv['type'] ?? null, 'function' => $nv['function'] ?? null, 'target_before' => $ov['target'] ?? null, 'target_after' => $nv['target'] ?? null, 'evidence' => $nv['evidence'] ?? null];
            }
            for ($p = $pairs; $p < count($idxs); $p++) {
                $added[] = $this->effectBrief($ne[$idxs[$p]]);
            }
            $gr[$key] = array_slice($rem, $pairs);
        }
        foreach ($gr as $idxs) {
            foreach ($idxs as $i) {
                $removed[] = $this->effectBrief($oe[$i]);
            }
        }
        return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'n' => count($added) + count($removed) + count($changed)];
    }

    // ── call graph ────────────────────────────────────────────────────────────
    private function diffCalls(array $old, array $new): array
    {
        $o = [];
        foreach ($old['call_graph'] ?? [] as $e) {
            $o[$this->callSig($e)] = $e;
        }
        $n = [];
        foreach ($new['call_graph'] ?? [] as $e) {
            $n[$this->callSig($e)] = $e;
        }
        $added = $removed = [];
        foreach (array_diff(array_keys($n), array_keys($o)) as $k) {
            $added[] = $this->callBrief($n[$k]);
        }
        foreach (array_diff(array_keys($o), array_keys($n)) as $k) {
            $removed[] = $this->callBrief($o[$k]);
        }
        return ['added' => $added, 'removed' => $removed, 'n' => count($added) + count($removed)];
    }

    // ── helpers de identidade/normalização ────────────────────────────────────
    private function byName(array $functions): array
    {
        $out = [];
        foreach ($functions as $f) {
            $out[strtoupper($f['name'])] = $f;
        }
        return $out;
    }

    private function tablesByAlias(array $det): array
    {
        $out = [];
        foreach ($det['tables'] ?? [] as $t) {
            $k = strtoupper($t['alias'] ?? $t['table'] ?? '');
            if ($k !== '') {
                $out[$k] = $t;
            }
        }
        return $out;
    }

    private function tableEvidence(array $det): array
    {
        $out = [];
        foreach ($det['tables'] ?? [] as $t) {
            $out[strtoupper($t['table'] ?? $t['alias'] ?? '')] = $t['evidence'] ?? null;
        }
        return $out;
    }

    /** "TAB.FIELD" => lista de papéis {read,write,where}. */
    private function fieldRoles(array $det): array
    {
        $out = [];
        foreach ($det['tables'] ?? [] as $t) {
            $tab = strtoupper($t['table'] ?? $t['alias'] ?? '');
            foreach (['read_fields' => 'read', 'write_fields' => 'write', 'where_fields' => 'where'] as $key => $role) {
                foreach ($t[$key] ?? [] as $f) {
                    $out[$tab . '.' . strtoupper($f)][] = $role;
                }
            }
        }
        foreach ($out as $k => $roles) {
            $out[$k] = array_values(array_unique($roles));
        }
        return $out;
    }

    private function queriesByFunction(array $det): array
    {
        $out = [];
        foreach ($det['queries'] ?? [] as $q) {
            $out[strtoupper((string) ($q['function'] ?? ''))][] = $q;
        }
        return $out;
    }

    private function fieldsFromQueries(array $queries, string $role): array
    {
        $out = [];
        foreach ($queries as $q) {
            foreach ($q[$role] ?? [] as $f) {
                $out[strtoupper($f)] = true;
            }
        }
        return array_keys($out);
    }

    private function normCalls(array $f): array
    {
        $out = [];
        foreach ($f['calls_internal'] ?? [] as $c) {
            $out[] = strtolower($c);
        }
        foreach ($f['calls_user'] ?? [] as $c) {
            $out[] = strtolower(preg_replace('/^U_/i', '', $c));
        }
        return array_values(array_unique($out));
    }

    private function sqlSig(array $q): string
    {
        return implode('|', [
            strtoupper($q['operation'] ?? ''), strtoupper((string) ($q['table'] ?? '')),
            $q['executor'] ?? '', $q['construction'] ?? '', $q['has_where'] ? '1' : '0',
            $this->sortedStr($q['read_fields'] ?? []), $this->sortedStr($q['write_fields'] ?? []),
            $this->sortedStr($q['where_fields'] ?? []), $this->sortedStr($q['risk_flags'] ?? []),
        ]);
    }

    private function sqlTarget(array $q): string
    {
        return strtoupper(($q['operation'] ?? '') . '|' . (string) ($q['table'] ?? ''));
    }

    private function sqlChange(array $o, array $n): array
    {
        $c = [];
        foreach (['executor', 'construction'] as $k) {
            if (($o[$k] ?? null) !== ($n[$k] ?? null)) {
                $c[$k] = ['before' => $o[$k] ?? null, 'after' => $n[$k] ?? null];
            }
        }
        if (($o['has_where'] ?? null) !== ($n['has_where'] ?? null)) {
            $c['has_where'] = ['before' => $o['has_where'] ?? null, 'after' => $n['has_where'] ?? null];
        }
        foreach (['read_fields', 'write_fields', 'where_fields', 'risk_flags'] as $k) {
            $this->putListDelta($c, $k, $this->up($o[$k] ?? []), $this->up($n[$k] ?? []));
        }
        return ['operation' => $n['operation'] ?? null, 'table' => $n['table'] ?? null, 'changes' => $c, 'evidence' => $n['evidence'] ?? null];
    }

    private function sqlBrief(array $q): array
    {
        return [
            'operation' => $q['operation'] ?? null, 'table' => $q['table'] ?? null,
            'executor' => $q['executor'] ?? null, 'construction' => $q['construction'] ?? null,
            'write_fields' => $q['write_fields'] ?? [], 'where_fields' => $q['where_fields'] ?? [],
            'risk_flags' => $q['risk_flags'] ?? [], 'function' => $q['function'] ?? null, 'evidence' => $q['evidence'] ?? null,
        ];
    }

    private function effectSig(array $e): string
    {
        return strtolower(($e['type'] ?? '') . '|' . ($e['target'] ?? '') . '|' . ($e['function'] ?? ''));
    }

    private function effectBrief(array $e): array
    {
        return ['type' => $e['type'] ?? null, 'target' => $e['target'] ?? null, 'function' => $e['function'] ?? null, 'evidence' => $e['evidence'] ?? null];
    }

    private function callSig(array $e): string
    {
        return strtolower(($e['from'] ?? '') . '->' . ($e['to'] ?? '') . '|' . ($e['called_as'] ?? '') . '|' . ($e['kind'] ?? ''));
    }

    private function callBrief(array $e): array
    {
        return ['from' => $e['from'] ?? null, 'to' => $e['to'] ?? null, 'called_as' => $e['called_as'] ?? null, 'kind' => $e['kind'] ?? null];
    }

    /** Acrescenta em $bag as chaves "<name>_added"/"<name>_removed" quando houver delta. */
    private function putListDelta(array &$bag, string $name, array $old, array $new): void
    {
        $add = array_values(array_diff($new, $old));
        $rem = array_values(array_diff($old, $new));
        if ($add) {
            $bag[$name . '_added'] = $add;
        }
        if ($rem) {
            $bag[$name . '_removed'] = $rem;
        }
    }

    private function up(array $a): array
    {
        return array_values(array_unique(array_map('strtoupper', $a)));
    }

    private function sortedStr(array $a): string
    {
        $a = array_map('strtoupper', $a);
        sort($a);
        return implode(',', $a);
    }

    // ── diff de linhas (evidência complementar) — poda prefixo/sufixo + LCS 2-linhas + teto ──
    private function lineStats(string $old, string $new): array
    {
        $norm = fn ($s) => str_replace("\r\n", "\n", $s);
        if ($old === '') {
            return ['added' => $new === '' ? 0 : substr_count($norm($new), "\n") + 1, 'removed' => 0];
        }
        $a = explode("\n", $norm($old));
        $b = explode("\n", $norm($new));
        $na = count($a);
        $nb = count($b);
        // poda prefixo comum
        $i = 0;
        while ($i < $na && $i < $nb && $a[$i] === $b[$i]) {
            $i++;
        }
        // poda sufixo comum
        $ea = $na - 1;
        $eb = $nb - 1;
        while ($ea >= $i && $eb >= $i && $a[$ea] === $b[$eb]) {
            $ea--;
            $eb--;
        }
        $ma = array_slice($a, $i, $ea - $i + 1);
        $mb = array_slice($b, $i, $eb - $i + 1);
        $la = count($ma);
        $lb = count($mb);
        if ($la === 0) {
            return ['added' => $lb, 'removed' => 0];
        }
        if ($lb === 0) {
            return ['added' => 0, 'removed' => $la];
        }
        if ($la * $lb > self::LCS_CELL_CAP) {
            // fallback não-quadrático: delta por multiconjunto de linhas do miolo
            return $this->multisetLineDelta($ma, $mb) + ['approx' => true];
        }
        $lcs = $this->lcsLen($ma, $mb);
        return ['added' => $lb - $lcs, 'removed' => $la - $lcs];
    }

    /** LCS (comprimento) com 2 linhas — O(la·lb) tempo, O(min) memória. */
    private function lcsLen(array $a, array $b): int
    {
        if (count($a) < count($b)) {
            [$a, $b] = [$b, $a];
        }
        $m = count($b);
        $prev = array_fill(0, $m + 1, 0);
        foreach ($a as $ai) {
            $curr = array_fill(0, $m + 1, 0);
            for ($j = 1; $j <= $m; $j++) {
                $curr[$j] = $ai === $b[$j - 1] ? $prev[$j - 1] + 1 : ($prev[$j] > $curr[$j - 1] ? $prev[$j] : $curr[$j - 1]);
            }
            $prev = $curr;
        }
        return $prev[$m];
    }

    private function multisetLineDelta(array $a, array $b): array
    {
        $ca = array_count_values($a);
        $cb = array_count_values($b);
        $removed = 0;
        foreach ($ca as $line => $cnt) {
            $removed += max(0, $cnt - ($cb[$line] ?? 0));
        }
        $added = 0;
        foreach ($cb as $line => $cnt) {
            $added += max(0, $cnt - ($ca[$line] ?? 0));
        }
        return ['added' => $added, 'removed' => $removed];
    }
}
