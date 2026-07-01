<?php

namespace App\Services;

use App\Documents\CrmProposalService;
use App\Models\CrmProposal;
use App\Models\CrmProposalSection;

/**
 * P-C.1 — gera as seções navegáveis do Portal a partir dos blocos estruturados já existentes
 * (proposal.conteudo + proposal.calc). As seções são persistidas (id estável p/ comentários/revisões
 * nas próximas fases); `content` é um snapshot HTML re-derivado em cada sync. `visible`/`order` do
 * usuário são preservados quando a linha já existe.
 */
class ProposalSectionService
{
    /** Ordem oficial das seções do Portal. */
    private const KEYS = ['resumo_executivo', 'escopo', 'premissas', 'cronograma', 'investimento', 'prazo', 'observacoes', 'aceite', 'anexos'];

    private const TITLES = [
        'resumo_executivo' => 'Resumo Executivo', 'escopo' => 'Escopo', 'premissas' => 'Premissas',
        'cronograma' => 'Cronograma', 'investimento' => 'Investimento', 'prazo' => 'Prazo de Pagamento',
        'observacoes' => 'Observações', 'aceite' => 'Aceite', 'anexos' => 'Anexos',
    ];

    /** Seções que aceitam comentários/revisões no Portal (regra do negócio): Escopo, Investimento e Prazo de Pagamento. */
    public const COMMENTABLE = ['escopo', 'investimento', 'prazo'];

    private const TIPO_LABELS = [
        'bh_fixo' => 'Banco de Horas Fixo', 'bh_mensal' => 'Banco de Horas Mensal',
        'on_demand' => 'Consultoria Sob Demanda', 'projeto_fechado' => 'Projeto Fechado', 'cloud' => 'Cloud Protheus',
    ];

    /** Garante as seções e devolve as VISÍVEIS (ordenadas) para o Portal. Sincroniza só se ainda não existem. */
    public function forPortal(CrmProposal $p): array
    {
        if (!$p->sections()->exists()) $this->sync($p);
        return $p->sections()->where('visible', true)->orderBy('order')->get()
            ->map(fn ($s) => ['key' => $s->section_key, 'title' => $s->title, 'order' => $s->order, 'content' => $s->content, 'data' => $s->data ?? []])
            ->values()->all();
    }

    /** Regenera conteúdo (HTML fallback) + data (estruturado p/ os renderers); preserva visible/order. */
    public function sync(CrmProposal $p): void
    {
        $conteudo = (array) ($p->conteudo ?? []);
        $calc     = $p->relationLoaded('calc') ? $p->calc : $p->calc()->first();
        $inputs   = (array) ($calc?->inputs ?? []);
        $outputs  = (array) ($calc?->outputs ?? []);

        $ord = 0;
        foreach (self::KEYS as $key) {
            $ord++;
            [$html, $defaultVisible, $data] = $this->build($key, $p, $conteudo, $inputs, $outputs);
            $existing = CrmProposalSection::where('crm_proposal_id', $p->id)->where('section_key', $key)->first();
            if ($existing) {
                $existing->update(['title' => self::TITLES[$key], 'content' => $html, 'data' => $data]);
            } else {
                CrmProposalSection::create([
                    'crm_proposal_id' => $p->id, 'section_key' => $key, 'title' => self::TITLES[$key],
                    'order' => $ord, 'content' => $html, 'data' => $data, 'visible' => $defaultVisible,
                ]);
            }
        }
    }

    /**
     * @return array{0:string,1:bool,2:array} [htmlFallback, defaultVisible, structuredData]
     *
     * FIDELIDADE: cada seção usa o CONTEÚDO ORIGINAL da proposta, VERBATIM, interpolado com as MESMAS
     * tags do PDF (CrmProposalService::fillTags). Nada é gerado, resumido, relabelado ou inventado.
     * Seção sem conteúdo original → visible=false (não aparece; não fabricamos texto p/ preencher).
     */
    private function build(string $key, CrmProposal $p, array $conteudo, array $inputs, array $outputs): array
    {
        switch ($key) {
            case 'resumo_executivo': return $this->resumo($p, $conteudo);
            case 'escopo':           return $this->escopo($p, $conteudo);
            case 'premissas':        return $this->premissas($p, $conteudo);
            case 'cronograma':       return $this->cronograma($p, $conteudo, $inputs);
            case 'investimento':     return $this->investimento($p, $conteudo);
            case 'prazo':            return $this->prazo($p, $conteudo, $inputs);
            case 'observacoes':      return $this->observacoes($p, $conteudo);
            case 'aceite':           return $this->aceite($conteudo);
            case 'anexos':           return $this->anexos($p);
        }
        return ['', false, []];
    }

    /** Interpola {tags} com a MESMA fonte do PDF — garante conteúdo idêntico. */
    private function fill(CrmProposal $p, ?string $text): string
    {
        $text = (string) $text;
        return $text === '' ? '' : app(CrmProposalService::class)->fillTags($p, $text);
    }

    /** Capa/identificação — apenas metadados FACTUAIS da proposta + card_texto verbatim. */
    private function resumo(CrmProposal $p, array $c): array
    {
        $card  = trim((string) ($c['card_texto'] ?? ''));
        $valor = 'R$ ' . number_format((float) $p->total, 2, ',', '.');
        $facts = array_values(array_filter([
            $p->customer?->name ? ['label' => 'Cliente', 'value' => (string) $p->customer->name] : null,
            $p->customer?->cgc  ? ['label' => 'CNPJ', 'value' => (string) $p->customer->cgc] : null,
            $p->vendedor?->name ? ['label' => 'Executivo', 'value' => (string) $p->vendedor->name] : null,
            ['label' => 'Modalidade', 'value' => self::TIPO_LABELS[$p->tipo ?? ''] ?? ($p->tipo ?? '—')],
            optional($p->data_emissao)->format('d/m/Y') ? ['label' => 'Data', 'value' => $p->data_emissao->format('d/m/Y')] : null,
            optional($p->data_validade)->format('d/m/Y') ? ['label' => 'Validade', 'value' => $p->data_validade->format('d/m/Y')] : null,
        ]));
        $data = ['card_texto' => $card ?: null, 'investimento' => $valor, 'facts' => $facts];

        $html = '';
        if ($card !== '') $html .= $this->txt2html($card);
        $html .= '<dl class="facts">';
        foreach (array_merge($facts, [['label' => 'Investimento', 'value' => $valor]]) as $f) $html .= '<div><dt>' . $this->esc($f['label']) . '</dt><dd>' . $this->esc($f['value']) . '</dd></div>';
        $html .= '</dl>';
        return [$html, true, $data];
    }

    /** Escopo VERBATIM: objetivo + escopo_funcional originais (interpolados como no PDF). */
    private function escopo(CrmProposal $p, array $c): array
    {
        $tipo = trim((string) ($c['escopo']['tipo_escopo'] ?? ''));
        $obj  = $this->fill($p, $c['escopo']['objetivo'] ?? '');
        $func = $this->fill($p, $c['escopo']['escopo_funcional'] ?? '');
        $data = ['tipo_escopo' => $tipo ?: null, 'objetivo' => $obj ?: null, 'escopo_funcional' => $func ?: null];

        $html = '';
        if ($tipo !== '') $html .= '<p class="badge">Escopo ' . $this->esc($tipo) . '</p>';
        if ($obj !== '')  $html .= '<p class="lead">' . $this->esc($obj) . '</p>';
        if ($func !== '') $html .= $this->txt2html($func);
        return [$html, ($obj !== '' || $func !== '' || $tipo !== ''), $data];
    }

    /** Premissas: SÓ se a proposta tiver esse conteúdo original. Nada é fabricado → senão, oculta. */
    private function premissas(CrmProposal $p, array $c): array
    {
        $txt = $this->fill($p, $c['premissas'] ?? ($c['escopo']['premissas'] ?? ''));
        if (trim($txt) === '') return ['', false, ['texto' => null]];
        return [$this->txt2html($txt), true, ['texto' => $txt]];
    }

    /** Cronograma/Prazo: início (verbatim) + duração (valor real da memória de cálculo). */
    private function cronograma(CrmProposal $p, array $c, array $inputs): array
    {
        $dur    = (int) ($inputs['duracao_meses'] ?? 0);
        $inicio = $this->fill($p, $c['prazo']['inicio_atendimento'] ?? '');
        $data = ['duracao_meses' => $dur ?: null, 'inicio' => trim($inicio) ?: null];
        if (!$dur && trim($inicio) === '') return ['', false, $data];
        $html = '';
        if ($dur > 0)            $html .= '<p><strong>Duração:</strong> ' . $dur . ' ' . ($dur === 1 ? 'mês' : 'meses') . '.</p>';
        if (trim($inicio) !== '') $html .= '<p><strong>Início:</strong> ' . $this->esc($inicio) . '</p>';
        return [$html, true, $data];
    }

    /** Investimento: valor total (real) + condições (verbatim) + blocos de texto VERBATIM (sem títulos inventados). */
    private function investimento(CrmProposal $p, array $c): array
    {
        $inv = (array) ($c['investimento'] ?? []);
        $prz = (array) ($c['prazo'] ?? []);
        $total = 'R$ ' . number_format((float) $p->total, 2, ',', '.');

        $cond = [];
        if (!empty($prz['parcelas']))   $cond[] = ['label' => 'Parcelamento', 'value' => $this->fill($p, (string) $prz['parcelas'])];
        if (!empty($prz['valor_pct']))  $cond[] = ['label' => 'Valor por parcela', 'value' => $this->fill($p, (string) $prz['valor_pct'])];
        if (!empty($prz['vencimento'])) $cond[] = ['label' => 'Vencimento', 'value' => $this->fill($p, (string) $prz['vencimento'])];

        $blocos = [];
        foreach (['sob_demanda', 'despesas_sp', 'despesas_fora'] as $k) {
            $val = $this->fill($p, (string) ($inv[$k] ?? ''));
            if (trim($val) !== '') $blocos[] = $val;
        }
        $data = ['total' => $total, 'condicoes' => $cond, 'blocos' => $blocos];

        $html = '<p class="total">Investimento total: <strong>' . $this->esc($total) . '</strong></p>';
        if ($cond) {
            $html .= '<dl class="facts">';
            foreach ($cond as $f) $html .= '<div><dt>' . $this->esc($f['label']) . '</dt><dd>' . $this->esc($f['value']) . '</dd></div>';
            $html .= '</dl>';
        }
        foreach ($blocos as $b) $html .= $this->txt2html($b);
        return [$html, true, $data];
    }

    /** Prazo de Pagamento VERBATIM: condições de pagamento + início + pagamento de despesas. */
    private function prazo(CrmProposal $p, array $c, array $inputs): array
    {
        $prz = (array) ($c['prazo'] ?? []);
        $cond = [];
        if (!empty($prz['parcelas']))   $cond[] = ['label' => 'Parcelamento', 'value' => $this->fill($p, (string) $prz['parcelas'])];
        if (!empty($prz['valor_pct']))  $cond[] = ['label' => 'Valor por parcela', 'value' => $this->fill($p, (string) $prz['valor_pct'])];
        if (!empty($prz['vencimento'])) $cond[] = ['label' => 'Vencimento', 'value' => $this->fill($p, (string) $prz['vencimento'])];
        $inicio = $this->fill($p, $prz['inicio_atendimento'] ?? '');
        $pgDesp = $this->fill($p, $prz['pagamento_despesas'] ?? '');
        $data = ['condicoes' => $cond, 'inicio' => trim($inicio) ?: null, 'pagamento_despesas' => trim($pgDesp) ?: null];
        if (!$cond && trim($inicio) === '' && trim($pgDesp) === '') return ['', false, $data];

        $html = '';
        if ($cond) {
            $html .= '<dl class="facts">';
            foreach ($cond as $f) $html .= '<div><dt>' . $this->esc($f['label']) . '</dt><dd>' . $this->esc($f['value']) . '</dd></div>';
            $html .= '</dl>';
        }
        if (trim($inicio) !== '') $html .= '<p><strong>Início:</strong> ' . $this->esc($inicio) . '</p>';
        if (trim($pgDesp) !== '') $html .= $this->txt2html($pgDesp);
        return [$html, true, $data];
    }

    /** Observações VERBATIM: pagamento de despesas + texto extra do aceite (quando existem). */
    private function observacoes(CrmProposal $p, array $c): array
    {
        $itens = [];
        $pg = $this->fill($p, $c['prazo']['pagamento_despesas'] ?? '');
        if (trim($pg) !== '') $itens[] = $pg;
        $extra = $this->fill($p, $c['aceite']['texto_extra'] ?? '');
        if (trim($extra) !== '') $itens[] = $extra;
        if (!$itens) return ['', false, ['items' => []]];
        return [implode('', array_map(fn ($t) => $this->txt2html($t), $itens)), true, ['items' => $itens]];
    }

    /** Aceite: dados da contratada (verbatim). Sem texto introdutório inventado. */
    private function aceite(array $c): array
    {
        $ct = (array) ($c['aceite']['contratada'] ?? []);
        $contratada = array_filter([
            'nome'     => trim((string) ($ct['nome'] ?? '')),
            'cnpj'     => trim((string) ($ct['cnpj'] ?? '')),
            'endereco' => trim((string) ($ct['endereco'] ?? '')),
            'cep'      => trim((string) ($ct['cep'] ?? '')),
        ], fn ($v) => $v !== '');
        if (!$contratada) return ['', false, ['contratada' => []]];
        $data = ['contratada' => $contratada];

        $html = '<dl class="facts">';
        foreach (['nome' => 'Contratada', 'cnpj' => 'CNPJ', 'endereco' => 'Endereço', 'cep' => 'CEP'] as $k => $label) {
            if (!empty($contratada[$k])) $html .= '<div><dt>' . $label . '</dt><dd>' . $this->esc($contratada[$k]) . '</dd></div>';
        }
        $html .= '</dl>';
        return [$html, true, $data];
    }

    private function anexos(CrmProposal $p): array
    {
        $html = '<p>A proposta completa também está disponível em PDF para download.</p>'
            . '<p><a class="dl" href="/api/v1/p/' . '__TOKEN__' . '/pdf" data-pdf="1">Baixar proposta (PDF)</a></p>';
        return [$html, true, ['pdf' => true]];
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Texto plano (\n + bullets "•") → HTML seguro (parágrafos + listas). */
    private function txt2html(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') return '';
        $blocks = preg_split('/\n{2,}/', $text);
        $out = '';
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));
            if (!$lines) continue;
            $bullets = array_filter($lines, fn ($l) => str_starts_with($l, '•') || str_starts_with($l, '-'));
            if (count($bullets) === count($lines)) {
                $out .= '<ul>' . implode('', array_map(fn ($l) => '<li>' . $this->esc(ltrim($l, "•- ")) . '</li>', $lines)) . '</ul>';
            } else {
                $out .= '<p>' . implode('<br>', array_map(fn ($l) => $this->esc($l), $lines)) . '</p>';
            }
        }
        return $out;
    }
}
