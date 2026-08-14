<?php

namespace App\SourceCode;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html as PhpWordHtml;

/**
 * Fase 4 — renderiza o documentation_json (verdade estruturada no Minutor) em formatos de saída:
 * Markdown, HTML, .docx (timbrado ERPSERV) e PDF. É apenas REPRESENTAÇÃO — a fonte da verdade
 * é o JSON. Compõe as 14 seções a partir de deterministic (fatos) + semantic (explicação);
 * seção semântica ausente vira "Análise semântica pendente" sem apagar os fatos.
 */
class SourceDocRenderer
{
    private const UNKNOWN = 'Não identificado automaticamente no código.';
    private const PENDING = 'Análise semântica pendente — reprocessável. Os dados técnicos abaixo permanecem válidos.';

    /** @param array $doc documentation_json · $isOutdated banner de desatualização */
    public function html(array $doc, bool $isOutdated = false, array $customer = []): string
    {
        $d = $doc['deterministic'] ?? [];
        $s = $doc['semantic'] ?? null;
        $id = $doc['identity'] ?? [];
        $v = $doc['version'] ?? [];
        $findings = $doc['security_findings'] ?? ($d['security_findings'] ?? []);
        $e = fn ($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $semPending = !$s || in_array($s['status'] ?? '', ['pending', 'failed'], true);

        $h = '<style>body{font-family:Arial,Helvetica,sans-serif;color:#12232b;font-size:11pt}'
            . 'h1{color:#0d4766;font-size:18pt;margin:0 0 4pt} h2{color:#0d4766;font-size:13pt;margin:14pt 0 4pt;border-bottom:1px solid #cfe0e3}'
            . 'table{border-collapse:collapse;width:100%;font-size:10pt} th,td{border:1px solid #b9cdd1;padding:4px 7px;text-align:left;vertical-align:top}'
            . 'th{background:#e8f1f2} .kv td:first-child{font-weight:bold;width:26%;color:#3a4a4d} .muted{color:#6b7d80} '
            . '.warn{background:#f7ead2;border-left:3px solid #b5741a;padding:8px 12px;margin:8px 0} '
            . '.crit{background:#fadedb;border-left:3px solid #c0392b;padding:8px 12px;margin:8px 0} .ni{color:#6b7d80;font-style:italic}</style>';

        $h .= '<h1>Documentação de Fonte — ' . $e($id['filename'] ?? '') . '</h1>';
        if ($isOutdated) {
            $h .= '<div class="warn"><b>⚠ Documentação possivelmente desatualizada:</b> o código do fonte mudou no repositório desde a versão descrita aqui (' . $e(substr((string) ($v['source_commit_sha'] ?? ''), 0, 8)) . ').</div>';
        }

        // 1. Identificação
        $h .= '<h2>1. Identificação</h2><table class="kv">';
        $h .= $this->kv($e, 'Fonte', $id['filename'] ?? '—');
        $h .= $this->kv($e, 'Cliente', $customer['name'] ?? ($id['customer_id'] ? ('#' . $id['customer_id']) : '—'));
        $h .= $this->kv($e, 'Tipo', $id['tipo'] ?? '—');
        $h .= $this->kv($e, 'Linguagem', $id['lang'] ?? '—');
        $h .= $this->kv($e, 'Repositório', ($id['owner'] ?? '') . '/' . ($id['repository'] ?? ''));
        $h .= $this->kv($e, 'Branch', $id['branch'] ?? '—');
        $h .= $this->kv($e, 'Caminho', $id['path'] ?? '—');
        $h .= $this->kv($e, 'Commit analisado', substr((string) ($v['source_commit_sha'] ?? ''), 0, 12) ?: '—');
        $h .= $this->kv($e, 'GMUD', $v['ticket_number'] ?? '—');
        $h .= $this->kv($e, 'Responsável', $v['responsavel'] ?? '—');
        $h .= $this->kv($e, 'Status da análise', $doc['status'] ?? '—');
        $h .= '</table>';

        // 2. Objetivo
        $h .= '<h2>2. Objetivo / Visão funcional</h2>';
        $h .= $semPending ? '<p class="ni">' . self::PENDING . '</p>' : '<p>' . $e($s['objetivo'] ?? self::UNKNOWN) . '</p>';

        // 3. Fluxo
        $h .= '<h2>3. Fluxo de execução</h2>';
        if (!$semPending && !empty($s['fluxo'])) {
            $h .= '<ol>';
            foreach ($s['fluxo'] as $step) {
                $h .= '<li>' . $e($step) . '</li>';
            }
            $h .= '</ol>';
        } else {
            $h .= '<p class="ni">' . ($semPending ? self::PENDING : self::UNKNOWN) . '</p>';
        }

        // 4. Funções
        $h .= '<h2>4. Funções</h2>';
        $semFn = [];
        foreach (($s['funcoes'] ?? []) as $f) {
            $semFn[strtolower($f['name'] ?? '')] = $f['finalidade'] ?? '';
        }
        foreach (($d['functions'] ?? []) as $f) {
            $h .= '<table class="kv"><tr><td>Função</td><td><b>' . $e($f['name']) . '</b> (' . $e($f['type']) . ')</td></tr>';
            $h .= $this->kv($e, 'Parâmetros', empty($f['params']) ? '—' : implode(', ', $f['params']));
            $h .= $this->kv($e, 'Retorno', empty($f['returns']) ? '—' : implode(' · ', $f['returns']));
            $chama = array_merge($f['calls_internal'] ?? [], $f['calls_user'] ?? []);
            $h .= $this->kv($e, 'Chama', empty($chama) ? '—' : implode(', ', $chama));
            $h .= $this->kv($e, 'Escreve dados', ($f['writes'] ?? false) ? 'sim' : 'não');
            $fin = $semFn[strtolower($f['name'])] ?? null;
            $h .= '<tr><td>Finalidade</td><td>' . ($fin ? $e($fin) : '<span class="ni">' . ($semPending ? 'pendente' : self::UNKNOWN) . '</span>') . '</td></tr></table>';
        }
        if (empty($d['functions'])) {
            $h .= '<p class="ni">Nenhuma função identificada.</p>';
        }

        // 5. Regras de negócio
        $h .= '<h2>5. Regras de negócio</h2>';
        if (!$semPending && !empty($s['regras_negocio'])) {
            $h .= '<ul>';
            foreach ($s['regras_negocio'] as $r) {
                $h .= '<li><b>' . $e($r['id'] ?? 'RN') . ':</b> ' . $e($r['descricao'] ?? '') . '</li>';
            }
            $h .= '</ul>';
        } else {
            $h .= '<p class="ni">' . ($semPending ? self::PENDING : self::UNKNOWN) . '</p>';
        }

        // 6. Tabelas e campos
        $h .= '<h2>6. Tabelas e campos</h2>';
        $semTb = [];
        foreach (($s['tabelas'] ?? []) as $t) {
            $semTb[strtoupper($t['alias'] ?? '')] = $t['finalidade'] ?? '';
        }
        if (!empty($d['tables'])) {
            $h .= '<table><tr><th>Tabela/Alias</th><th>Acesso</th><th>Campos</th><th>Finalidade</th></tr>';
            foreach ($d['tables'] as $t) {
                $fin = $semTb[strtoupper($t['alias'])] ?? '';
                $h .= '<tr><td><b>' . $e($t['alias']) . '</b>' . (($t['dynamic'] ?? false) ? ' <span class="muted">(dinâmico)</span>' : '') . '</td>'
                    . '<td>' . $e(implode(', ', $t['access'] ?? [])) . '</td>'
                    . '<td>' . $e(implode(', ', $t['fields'] ?? []) ?: '—') . '</td>'
                    . '<td>' . ($fin ? $e($fin) : '<span class="ni">' . ($semPending ? 'pendente' : self::UNKNOWN) . '</span>') . '</td></tr>';
            }
            $h .= '</table>';
        } else {
            $h .= '<p class="ni">Nenhuma tabela identificada no código.</p>';
        }

        // 7. Queries
        $h .= '<h2>7. Queries / SQL</h2>';
        if (!empty($d['queries'])) {
            $h .= '<table><tr><th>Operação</th><th>Tabelas</th><th>Campos</th></tr>';
            foreach ($d['queries'] as $q) {
                $h .= '<tr><td>' . $e($q['operation']) . '</td><td>' . $e(implode(', ', $q['tables'] ?? [])) . '</td><td>' . $e(implode(', ', $q['fields'] ?? []) ?: '—') . '</td></tr>';
            }
            $h .= '</table>';
        } else {
            $h .= '<p class="ni">Nenhuma query SQL identificada.</p>';
        }

        // 8. Dependências
        $h .= '<h2>8. Dependências</h2><table class="kv">';
        $h .= $this->kv($e, 'Includes', implode(', ', $d['includes'] ?? []) ?: '—');
        $h .= $this->kv($e, 'Funções TOTVS', implode(', ', $d['totvs_calls'] ?? []) ?: '—');
        $h .= $this->kv($e, 'Funções de usuário', implode(', ', $d['user_calls'] ?? []) ?: '—');
        $h .= '</table>';

        // 9. Integrações
        $h .= '<h2>9. Integrações</h2>';
        $integr = !$semPending ? ($s['integracoes'] ?? []) : array_map(fn ($x) => ($x['type'] ?? '') . ' (' . ($x['tech'] ?? '') . ')', $d['integrations'] ?? []);
        if (!empty($integr)) {
            $h .= '<ul>';
            foreach ($integr as $x) {
                $h .= '<li>' . $e(is_array($x) ? json_encode($x) : $x) . '</li>';
            }
            $h .= '</ul>';
        } else {
            $h .= '<p class="ni">Nenhuma integração externa identificada no código.</p>';
        }

        // 10. Entradas e saídas
        $h .= '<h2>10. Entradas e saídas</h2>';
        if (!$semPending && (!empty($s['entradas']) || !empty($s['saidas']))) {
            $h .= '<b>Entradas:</b><ul>' . implode('', array_map(fn ($x) => '<li>' . $e($x) . '</li>', $s['entradas'] ?? [])) . '</ul>';
            $h .= '<b>Saídas:</b><ul>' . implode('', array_map(fn ($x) => '<li>' . $e($x) . '</li>', $s['saidas'] ?? [])) . '</ul>';
        } else {
            $h .= '<p class="ni">' . ($semPending ? self::PENDING : self::UNKNOWN) . '</p>';
        }

        // 11. Tratamento de erros
        $h .= '<h2>11. Tratamento de erros</h2>';
        $h .= $semPending
            ? '<p class="ni">' . self::PENDING . ' Sinais determinísticos: ' . $e(json_encode($d['error_handling'] ?? [])) . '</p>'
            : '<p>' . $e($s['tratamento_erros'] ?? self::UNKNOWN) . '</p>';

        // 12. Efeitos colaterais
        $h .= '<h2>12. Impactos / efeitos colaterais</h2>';
        $ef = !$semPending ? ($s['efeitos_colaterais'] ?? []) : array_map(fn ($x) => ($x['type'] ?? '') . ' → ' . ($x['target'] ?? ''), $d['write_effects'] ?? []);
        if (!empty($ef)) {
            $h .= '<ul>' . implode('', array_map(fn ($x) => '<li>' . $e(is_array($x) ? json_encode($x) : $x) . '</li>', $ef)) . '</ul>';
        } else {
            $h .= '<p class="ni">Nenhum efeito de escrita identificado.</p>';
        }

        // 13. Pontos de atenção
        $h .= '<h2>13. Pontos de atenção</h2>';
        if (!$semPending && !empty($s['pontos_atencao'])) {
            $h .= '<ul>' . implode('', array_map(fn ($x) => '<li>' . $e($x) . '</li>', $s['pontos_atencao'])) . '</ul>';
        } elseif ($semPending) {
            $h .= '<p class="ni">' . self::PENDING . '</p>';
        } else {
            $h .= '<p class="ni">Nenhum ponto de atenção específico.</p>';
        }
        if (!empty($findings)) {
            $h .= '<div class="crit"><b>🔒 Segurança:</b><ul>';
            foreach ($findings as $f) {
                $h .= '<li>' . $e($f['type'] ?? '') . ' (linha ' . $e($f['location'] ?? '?') . ', ' . $e($f['severity'] ?? '') . ')</li>';
            }
            $h .= '</ul></div>';
        }

        // 14. Histórico
        $h .= '<h2>14. Histórico de alterações</h2><table><tr><th>GMUD</th><th>Commit</th><th>Responsável</th><th>Resumo</th></tr>';
        $resumo = !$semPending ? ($s['resumo_alteracao'] ?? '—') : '—';
        $h .= '<tr><td>' . $e($v['ticket_number'] ?? '—') . '</td><td>' . $e(substr((string) ($v['source_commit_sha'] ?? ''), 0, 8)) . '</td><td>' . $e($v['responsavel'] ?? '—') . '</td><td>' . $e($resumo) . '</td></tr></table>';
        $h .= '<p class="muted" style="font-size:8pt;margin-top:16pt">Documentação gerada automaticamente pelo Minutor · representação do estado estruturado.</p>';

        return $h;
    }

    public function markdown(array $doc, bool $isOutdated = false, array $customer = []): string
    {
        // Converte o HTML das seções em Markdown simples (headings, listas, tabelas viram texto).
        $html = $this->html($doc, $isOutdated, $customer);
        $html = preg_replace('#<style>.*?</style>#s', '', $html);
        $md = $html;
        $md = preg_replace('#<h1[^>]*>(.*?)</h1>#is', "# $1\n", $md);
        $md = preg_replace('#<h2[^>]*>(.*?)</h2>#is', "\n## $1\n", $md);
        $md = preg_replace('#<li[^>]*>(.*?)</li>#is', "- $1\n", $md);
        $md = preg_replace('#</?(ul|ol)[^>]*>#i', '', $md);
        $md = preg_replace('#<tr[^>]*>(.*?)</tr>#is', "$1|\n", $md);
        $md = preg_replace('#<t[hd][^>]*>(.*?)</t[hd]>#is', "| $1 ", $md);
        $md = preg_replace('#</?(table|b|div|span|ol)[^>]*>#i', '', $md);
        $md = preg_replace('#<p[^>]*>(.*?)</p>#is', "$1\n", $md);
        $md = html_entity_decode(strip_tags($md), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $md)) . "\n";
    }

    /** @return string bytes do .docx (timbrado ERPSERV: banner no header + rodapé de contato) */
    public function docx(array $doc, bool $isOutdated = false, array $customer = []): string
    {
        $php = new PhpWord();
        $sec = $php->addSection(['marginTop' => 1400, 'marginBottom' => 1000, 'marginLeft' => 900, 'marginRight' => 900]);
        $logo = base_path('resources/templates/erpserv-logo.png');
        if (is_file($logo)) {
            $sec->addHeader()->addImage($logo, ['width' => 480, 'height' => 52, 'alignment' => 'center']);
        }
        $foot = $sec->addFooter();
        $foot->addText('www.erpserv.com.br    ·    +55 11 3230.9647    ·    contato@erpserv.com.br', ['size' => 8, 'color' => '6b7d80'], ['alignment' => 'center']);

        // PhpWord Html::addHtml não processa <style>/classes — remove o bloco (usa bordas padrão).
        $body = preg_replace('#<style>.*?</style>#s', '', $this->html($doc, $isOutdated, $customer));
        PhpWordHtml::addHtml($sec, $body, false, false);

        $tmp = tempnam(sys_get_temp_dir(), 'srcdoc') . '.docx';
        $php->save($tmp, 'Word2007');
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    /** @return string bytes do PDF (via dompdf) */
    public function pdf(array $doc, bool $isOutdated = false, array $customer = []): string
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->html($doc, $isOutdated, $customer))->output();
    }

    private function kv(callable $e, string $k, $v): string
    {
        return '<tr><td>' . $e($k) . '</td><td>' . $e((string) $v) . '</td></tr>';
    }
}
