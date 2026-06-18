<?php

namespace App\Documents;

use App\Models\Contract;
use App\Models\CrmOpportunity;
use App\Models\CrmProposal;
use App\Models\CrmProposalCalc;
use App\Models\Customer;
use App\Models\DocumentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1.2 — ciclo de vida da Proposta integrado à Plataforma de Documentos + numeração única.
 * Proposta consome o código UMA vez (na criação). Revisão = nova versão (mesmo código).
 * PDF é gerado sob demanda via DocumentService (fila 'documents', Chromium/Gotenberg).
 */
class CrmProposalService
{
    /** tipo → módulo (template-base centralizado + módulo por tipo). */
    public const TEMPLATES = [
        'bh_fixo'         => 'pdf.documents.proposta.modulos.banco-horas-fixo',
        'bh_mensal'       => 'pdf.documents.proposta.modulos.banco-horas-mensal',
        'on_demand'       => 'pdf.documents.proposta.modulos.on-demand',
        'projeto_fechado' => 'pdf.documents.proposta.modulos.projeto-fechado',
    ];

    public function __construct(
        private CrmProposalCalcService $calc,
        private DocumentNumberService $numbers,
        private DocumentService $documents,
    ) {}

    /** Cria a proposta V1: memória congelada + reserva de código. NÃO gera PDF. */
    public function criar(array $spec, User $actor): CrmProposal
    {
        return DB::transaction(function () use ($spec, $actor) {
            $opp = CrmOpportunity::findOrFail($spec['opportunity_id']);
            $customerId = $opp->customer_id;
            $modo = $spec['modo_faturamento'] ?? 'por_hora';

            $calc = $this->calc->persist(['versao' => 1, 'modo_faturamento' => $modo], $spec['inputs']);

            $numero = (int) CrmProposal::where('opportunity_id', $opp->id)->max('numero') + 1;
            $proposal = CrmProposal::create([
                'opportunity_id' => $opp->id,
                'customer_id'    => $customerId,
                'tipo'           => $spec['tipo'] ?? 'bh_fixo',
                'numero'         => $numero,
                'versao'         => 1,
                'status'         => 'em_elaboracao',
                'valor'          => $calc->faturamento,
                'descontos'      => $calc->desconto_valor,
                'memoria_calculo' => $calc->outputs,
                'calc_id'        => $calc->id,
                'vendedor_id'    => $opp->responsavel_id,
                'data_emissao'   => now()->toDateString(),
                'data_validade'  => $spec['data_validade'] ?? now()->addDays(15)->toDateString(),
                'created_by_id'  => $actor->id,
            ]);
            $calc->update(['proposal_id' => $proposal->id]);

            // Reserva o código UMA vez (motor único).
            $res = $this->numbers->reservar($customerId, 'proposta', [
                'entity_type' => 'CRM_PROPOSAL', 'entity_id' => $proposal->id,
            ], $actor->id);
            $proposal->update(['codigo' => $res['codigo']]);

            $this->logEvent($proposal, 'criado', ['codigo' => $res['codigo'], 'versao' => 1], $actor);
            $this->syncOppValor($proposal);
            return $proposal->fresh(['calc']);
        });
    }

    /** Revisão = nova versão (mesmo código, novo snapshot de memória). Histórico preservado. */
    public function revisar(CrmProposal $proposal, array $inputs, User $actor, ?string $modo = null): CrmProposal
    {
        return DB::transaction(function () use ($proposal, $inputs, $actor, $modo) {
            $nextV = (int) CrmProposal::where('codigo', $proposal->codigo)->max('versao') + 1;
            $modo = $modo ?? ($proposal->calc->modo_faturamento ?? 'por_hora');
            $calc = $this->calc->persist(['versao' => $nextV, 'modo_faturamento' => $modo], $inputs);

            $nova = CrmProposal::create([
                'opportunity_id' => $proposal->opportunity_id,
                'customer_id'    => $proposal->customer_id,
                'codigo'         => $proposal->codigo,   // MESMO código — não reserva novo
                'tipo'           => $proposal->tipo,
                'numero'         => $proposal->numero,
                'versao'         => $nextV,
                'status'         => 'em_elaboracao',
                'valor'          => $calc->faturamento,
                'descontos'      => $calc->desconto_valor,
                'memoria_calculo' => $calc->outputs,
                'calc_id'        => $calc->id,
                'vendedor_id'    => $proposal->vendedor_id,
                'data_emissao'   => now()->toDateString(),
                'data_validade'  => $proposal->data_validade,
                'created_by_id'  => $actor->id,
            ]);
            $calc->update(['proposal_id' => $nova->id]);

            $this->logEvent($nova, 'revisado', ['codigo' => $proposal->codigo, 'de_versao' => $proposal->versao, 'para_versao' => $nextV], $actor);
            return $nova->fresh(['calc']);
        });
    }

    /** Gera o PDF da proposta via plataforma (fila 'documents'). */
    /**
     * Edição IN-PLACE (enquanto em_elaboracao): recalcula a memória de cálculo e grava conteúdo/valor
     * SEM gerar nova versão. (Nova versão = revisar().)
     */
    public function editar(CrmProposal $p, array $spec, User $actor): CrmProposal
    {
        return DB::transaction(function () use ($p, $spec, $actor) {
            // troca de tipo/template (seletor no editor) — reflete no modo de faturamento.
            if (!empty($spec['tipo']) && $spec['tipo'] !== $p->tipo) {
                $p->tipo = $spec['tipo'];
            }
            $inputs = array_key_exists('inputs', $spec) ? (array) $spec['inputs'] : (array) ($p->calc?->inputs ?? []);
            $modo   = $spec['modo_faturamento'] ?? (!empty($spec['tipo']) ? ($spec['tipo'] === 'projeto_fechado' ? 'valor_fixo' : 'por_hora') : ($p->calc?->modo_faturamento ?? ($p->tipo === 'projeto_fechado' ? 'valor_fixo' : 'por_hora')));
            $out    = $this->calc->compute($inputs, $modo);

            if ($p->calc) {
                $p->calc->update([
                    'inputs' => $inputs, 'outputs' => $out, 'modo_faturamento' => $modo,
                    'custo_total' => $out['custo_total'], 'faturamento' => $out['faturamento'],
                    'premios_total' => $out['premios_total'], 'desconto_valor' => $out['desconto_valor'],
                    'margem_pct' => $out['margem_pct'], 'lucro_liquido' => $out['lucro_liquido'],
                ]);
            } else {
                $calc = $this->calc->persist(['versao' => $p->versao, 'modo_faturamento' => $modo, 'proposal_id' => $p->id, 'opportunity_id' => $p->opportunity_id], $inputs);
                $p->calc_id = $calc->id;
            }
            if (array_key_exists('conteudo', $spec)) $p->conteudo = (array) $spec['conteudo'];
            $p->valor     = $out['faturamento'];
            $p->descontos = $out['desconto_valor'];
            if (!empty($spec['data_validade'])) $p->data_validade = $spec['data_validade'];
            $p->save();

            $this->logEvent($p, 'editado', ['versao' => $p->versao], $actor);
            $this->syncOppValor($p);
            return $p->fresh(['calc', 'customer', 'vendedor']);
        });
    }

    public function gerarDocumento(CrmProposal $proposal, User $actor, bool $sync = false): \App\Models\Document
    {
        $spec = [
            'document_type' => 'proposta',
            // Render orientado a ARTWORK real (slides SVG do material institucional) + overlay dinâmico.
            'template'      => 'pdf.documents.proposta.render',
            'renderer'      => 'chromium',
            'entity_type'   => 'CRM_PROPOSAL',
            'entity_id'     => $proposal->id,
            'codigo'        => $proposal->codigo,
            'versao'        => $proposal->versao,
            'status'        => 'gerado',
            // Página 16:9 (1280x720px @96dpi = 13.333in x 7.5in) — fiel ao deck institucional.
            'opts'          => ['paperWidth' => 13.333, 'paperHeight' => 7.5, 'preferCssPageSize' => true],
        ];
        $data = $this->buildRenderData($proposal);
        $doc = $sync
            ? $this->documents->generate($spec, $data, $actor)        // render na hora (UX do botão)
            : $this->documents->generateAsync($spec, $data, $actor);  // fila (lote/automação)
        $proposal->update(['document_id' => $doc->id]);
        $this->logEvent($proposal, 'gerado', ['document_id' => $doc->id, 'versao' => $proposal->versao], $actor);
        return $doc;
    }

    public function baixar(CrmProposal $p, User $actor): void
    {
        $this->logEvent($p, 'baixado', ['document_id' => $p->document_id, 'versao' => $p->versao], $actor);
    }

    public function aprovar(CrmProposal $p, User $actor): CrmProposal
    {
        $p->update(['status' => 'aprovada']);
        $this->logEvent($p, 'aprovado', [], $actor);
        return $p;
    }

    public function cancelar(CrmProposal $p, User $actor): CrmProposal
    {
        $p->update(['status' => 'cancelada']);
        $this->logEvent($p, 'cancelado', ['nota' => 'código mantém-se reservado'], $actor);
        return $p;
    }

    public function reativar(CrmProposal $p, User $actor): CrmProposal
    {
        $p->update(['status' => 'reativada']);
        $this->logEvent($p, 'reativado', ['codigo' => $p->codigo], $actor);
        return $p;
    }

    /** Conversão: contrato herda o MESMO código (project_code_preview); projeto adota depois. */
    public function converter(CrmProposal $p, array $spec, User $actor): Contract
    {
        if ($p->status !== 'aprovada') {
            throw new \RuntimeException('Só propostas APROVADAS podem ser convertidas.');
        }
        $customer = Customer::find($p->customer_id);

        return DB::transaction(function () use ($p, $spec, $actor, $customer) {
            $contract = Contract::create([
                'customer_id'         => $p->customer_id,
                'project_name'        => $p->codigo . ' — ' . optional($p->opportunity)->title,
                'categoria'           => $spec['categoria'] ?? 'projeto',
                'contract_type_id'    => $spec['contract_type_id'] ?? null,
                'tipo_faturamento'    => $spec['tipo_faturamento'] ?? null,
                'valor_projeto'       => $p->valor,
                'project_code_preview' => $p->codigo,   // HERANÇA do código comercial
                'executivo_conta_id'  => $customer?->executive_id,
                'vendedor_id'         => $p->vendedor_id,
                'status'              => Contract::STATUS_RASCUNHO,
                'kanban_status'       => Contract::KANBAN_BACKLOG,
                'created_by_id'       => $actor->id,
            ]);
            $p->update(['status' => 'convertida']);
            $this->logEvent($p, 'convertido', ['contract_id' => $contract->id, 'codigo' => $p->codigo], $actor);
            return $contract;
        });
    }

    /**
     * Payload do render orientado a ARTWORK: slides SVG originais + overlays dinâmicos.
     * Os slides estáticos (problemas/soluções/processos/suporte/aceite/obrigado) = artwork puro.
     * Os dinâmicos (capa/escopo/investimento/prazo) recebem overlay posicionado (cobre placeholder
     * com a cor exata do fundo + escreve o valor real). Mecanismo validado na capa.
     */
    public function buildRenderData(CrmProposal $p, string $assetMode = 'datauri'): array
    {
        $c = $p->calc;
        $brl = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
        $tipo = $p->tipo ?: 'bh_fixo';
        $inputs   = (array) ($c->inputs ?? []);
        $conteudo = (array) ($p->conteudo ?? []);
        $def      = $this->proposalDefaults($tipo);
        $contratada = $this->contratadaConfig();

        // resolução de asset: data-URI (PDF/Gotenberg, que não vê file://) ou URL (preview leve no browser).
        $asset = function (string $rel) use ($assetMode) {
            if ($assetMode === 'url') {
                return '/api/v1/crm/proposals/artwork?path=' . rawurlencode($rel);
            }
            return DocumentAssets::dataUri($rel);
        };

        // slides (artwork real do tipo). PNG 200dpi (pdftoppm) — transparência correta.
        $slideCount = $tipo === 'projeto_fechado' ? 9 : 10;
        $slides = [];
        for ($i = 1; $i <= $slideCount; $i++) {
            if ($u = $asset(sprintf('slides/%s/slide-%02d.png', $tipo, $i))) $slides[] = $u;
        }
        if (empty($slides)) {
            for ($i = 1; $i <= 10; $i++) {
                if ($u = $asset(sprintf('slides/bh_fixo/slide-%02d.png', $i))) $slides[] = $u;
            }
        }

        // números (memória de cálculo)
        $horas   = (int) ($inputs['horas_consultoria'] ?? 0);
        $vhCli   = (float) ($inputs['valor_hora_cliente'] ?? $inputs['venda_h'] ?? 0);
        $total   = $horas * $vhCli;
        $dur     = (int) ($inputs['duracao_meses'] ?? 12);
        $valorProjeto = (float) ($inputs['valor_projeto'] ?? $inputs['valor_fixo'] ?? $total);
        $escopoTexto  = trim((string) ($inputs['escopo_texto'] ?? '')) ?: 'serviços especializados em ERP Protheus, Infraestrutura e Power BI';
        $cliente = optional($p->customer)->name ?? '—';
        $cnpj    = optional($p->customer)->cgc ?? '—';
        $exec    = optional($p->vendedor)->name ?? '—';
        $data    = optional($p->data_emissao)->format('d/m/Y') ?? now()->format('d/m/Y');

        $BG = '#442B7E'; $T = '#10AAA5';

        // helpers (espaço 1280x720, amarrado à rasterização do artwork)
        $mask = fn ($l, $t, $w, $h, $bg) => '<div style="position:absolute;left:' . $l . 'px;top:' . $t . 'px;width:' . $w . 'px;height:' . $h . 'px;background:' . $bg . '"></div>';
        $cel  = fn ($l, $t, $w, $al, $txt, $st = '') => '<div style="position:absolute;left:' . $l . 'px;top:' . $t . 'px;width:' . $w . 'px;text-align:' . $al . ';font-family:\'Roboto Condensed\',Arial,sans-serif;' . $st . '">' . $txt . '</div>';
        $frase = fn ($l, $t, $w, $txt, $sz, $col, $wt = 300) => '<div style="position:absolute;left:' . $l . 'px;top:' . $t . 'px;width:' . $w . 'px;white-space:nowrap;font-family:\'Roboto Condensed\';font-weight:' . $wt . ';font-size:' . $sz . 'px;color:' . $col . '">' . $txt . '</div>';
        // bloco multilinha (sem nowrap) p/ parágrafos reescritos
        $bloco = fn ($l, $t, $w, $txt, $sz, $col, $lh = 1.3, $wt = 300) => '<div style="position:absolute;left:' . $l . 'px;top:' . $t . 'px;width:' . $w . 'px;font-family:\'Roboto Condensed\';font-weight:' . $wt . ';font-size:' . $sz . 'px;line-height:' . $lh . ';color:' . $col . '">' . $txt . '</div>';
        // overlay-on-override: só emite quando o conteudo difere do padrão do deck (intocado = artwork).
        $over = function (string $path, callable $render) use ($conteudo, $def) {
            $val = data_get($conteudo, $path);
            if ($val === null || $val === '' || $val === data_get($def, $path)) return '';
            return $render($val);
        };
        $nl2br = fn ($s) => nl2br(e($s));

        $overlays = [];

        // ===== CAPA (índice 0) — dados + logo do cliente (upload) =====
        $overlays[0] = $mask(178, 426, 566, 130, $BG)
            . '<div style="position:absolute;left:180px;top:430px;font-size:19px;line-height:1.46;color:#fff;font-family:\'Roboto Condensed\',Arial,sans-serif;font-weight:300">'
            . 'ID: <span style="color:' . $T . '">' . e($p->codigo) . '</span> Versão: <span style="color:' . $T . '">' . str_pad((string) $p->versao, 2, '0', STR_PAD_LEFT) . '</span><br>'
            . 'CLIENTE: <span style="color:' . $T . '">' . e($cliente) . '</span><br>'
            . 'CNPJ: <span style="color:' . $T . '">' . e($cnpj) . '</span><br>'
            . 'EXECUTIVO: <span style="color:' . $T . '">' . e($exec) . '</span><br>'
            . 'DATA: <span style="color:' . $T . '">' . e($data) . '</span></div>';
        // logo do cliente: ERASE a caixa branca-placeholder (cobre com o fundo índigo) e coloca o logo
        // TRANSPARENTE por cima — o retângulo branco do deck é só o guia de posição.
        if ($logo = $this->resolveLogo($conteudo, $assetMode)) {
            $overlays[0] .= $mask(974, 400, 280, 152, $BG)
                . '<div style="position:absolute;left:980px;top:406px;width:268px;height:140px;display:flex;align-items:center;justify-content:center">'
                . '<img src="' . $logo . '" style="max-width:100%;max-height:100%;object-fit:contain"></div>';
        }

        // ===== tabelas de INVESTIMENTO =====
        $tabelaHoras = fn () => $mask(351, 223, 856, 55, '#cfd5e9')
            . $cel(335, 241, 265, 'center', (string) $horas, 'font-size:16px;color:#3a3a3a')
            . $cel(600, 241, 268, 'center', $brl($vhCli), 'font-size:16px;color:#3a3a3a')
            . $cel(868, 241, 338, 'center', $brl($total), 'font-size:16px;color:#3a3a3a')
            . $mask(866, 279, 342, 40, '#5eb4aa')
            . $cel(866, 290, 340, 'center', $brl($total), 'font-size:18px;font-weight:800;color:#fff');
        $tabelaValor = fn ($v) => $mask(636, 248, 572, 54, '#cfd5e9')
            . $cel(600, 264, 268, 'center', $brl($v), 'font-size:16px;color:#3a3a3a')
            . $cel(868, 264, 338, 'center', $brl($v), 'font-size:16px;color:#3a3a3a')
            . $mask(866, 304, 342, 40, '#5eb4aa')
            . $cel(866, 315, 340, 'center', $brl($v), 'font-size:18px;font-weight:800;color:#fff');
        $escopoObjetivo = fn ($txt) => $mask(274, 206, 948, 28, '#cfd5e9')
            . $frase(283, 210, 940, $txt, 13.5, '#33335a');

        // bloco centralizado (card índigo)
        $blocoC = fn ($l, $t, $w, $txt, $sz, $col, $lh = 1.25) => '<div style="position:absolute;left:' . $l . 'px;top:' . $t . 'px;width:' . $w . 'px;text-align:center;font-family:\'Roboto Condensed\';font-weight:300;font-size:' . $sz . 'px;line-height:' . $lh . ';color:' . $col . '">' . $txt . '</div>';

        // ===== ESCOPO (idx3) — TIPO DE ESCOPO (header índigo) + ESCOPO FUNCIONAL (painel lavanda) =====
        $tipoEscopoOverlay = $over('escopo.tipo_escopo', fn ($v) => $mask(263, 150, 210, 32, '#442B7E')
            . $frase(268, 156, 200, '<b style="font-weight:700">' . e($v) . '</b>', 14, '#ffffff', 700));
        $escopoFuncionalOverlay = $over('escopo.escopo_funcional', fn ($v) => $mask(258, 298, 1000, 400, '#e8ebf4')
            . $bloco(264, 302, 990, $nl2br($v), 16, '#4a4a66', 1.4));

        // ===== INVESTIMENTO (overrides de texto) — card índigo + sob demanda + despesas (corpo cinza) =====
        $invTexto = $over('investimento.card_texto', fn ($v) => $mask(50, 486, 252, 64, '#442B7E')
                . $blocoC(50, 492, 252, $nl2br($v), 17, '#ffffff', 1.2))
            . $over('investimento.sob_demanda', fn ($v) => $mask(350, 353, 902, 40, '#f1f1f1')
                . $bloco(355, 357, 892, $nl2br($v), 13.5, '#595959', 1.3))
            . $over('investimento.despesas_sp', fn ($v) => $mask(350, 427, 902, 40, '#f1f1f1')
                . $bloco(355, 431, 892, $nl2br($v), 13.5, '#595959', 1.3))
            . $over('investimento.despesas_fora', fn ($v) => $mask(350, 503, 902, 54, '#f1f1f1')
                . $bloco(355, 507, 892, $nl2br($v), 13.5, '#595959', 1.3));

        // ===== PRAZO (overrides de texto) — card índigo + início (corpo cinza) + pagamento =====
        $prazoTexto = $over('prazo.card_texto', fn ($v) => $mask(50, 486, 252, 64, '#442B7E')
                . $blocoC(50, 492, 252, $nl2br($v), 17, '#ffffff', 1.2))
            . $over('prazo.inicio_atendimento', fn ($v) => $mask(205, 250, 545, 60, '#f1f1f1')
                . $bloco(210, 253, 445, $nl2br($v), 13.5, '#595959', 1.3))
            . $over('prazo.pagamento_despesas', fn ($v) => $mask(208, 505, 905, 50, '#f1f1f1')
                . $bloco(213, 509, 895, $nl2br($v), 13.5, '#595959', 1.3));
        // parcelas (BH Fixo / Projeto Fechado): linha lavanda y390-430; cols PARCELAS|VALOR %|VENCIMENTO.
        $parcelasTexto = $over('prazo.parcelas', fn ($v) => $mask(215, 392, 322, 38, '#cfd5e9')
                . $cel(215, 401, 322, 'center', e($v), 'font-size:15px;color:#595959'))
            . $over('prazo.valor_pct', fn ($v) => $mask(540, 392, 328, 38, '#cfd5e9')
                . $cel(540, 401, 328, 'center', '<b style="font-weight:700">' . e($v) . '</b>', 'font-size:15px;color:#3a3a3a'))
            . $over('prazo.vencimento', fn ($v) => $mask(872, 392, 366, 38, '#cfd5e9')
                . $cel(872, 401, 366, 'center', e($v), 'font-size:14px;color:#595959'));

        // ===== ACEITE (índice = slideCount-2 → 7 nos de 10 / 7 no PF de 9... PF aceite=idx7) =====
        // Dados da Contratada (config global) — overlay-on-override vs o texto do deck.
        $aceiteIdx = $tipo === 'projeto_fechado' ? 7 : 8;

        switch ($tipo) {
            case 'bh_mensal':
                $overlays[3] = $escopoObjetivo('Aquisição de pacote de consultoria de <b style="font-weight:700">' . $horas . ' horas mensais</b> para ' . e($escopoTexto) . '.')
                    . $mask(262, 569, 988, 60, '#e8ebf4')
                    . $bloco(268, 574, 976, 'Será disponibilizado <b style="font-weight:700">' . $horas . ' horas</b> mensais para utilização dos itens acima, com todas as especialidades multidisciplinares da ERPServ.', 18, '#4a4a66', 1.32)
                    . $tipoEscopoOverlay;
                $overlays[6] = $tabelaHoras() . $invTexto;
                // PRAZO: VALOR da parcela mensal (col meio x643-930) + textos override.
                $overlays[7] = $mask(645, 382, 284, 55, '#cfd5e9')
                    . $cel(645, 400, 284, 'center', $brl($total), 'font-size:15px;font-weight:700;color:#3a3a3a')
                    . $prazoTexto;
                break;

            case 'on_demand':
                $overlays[3] = $escopoFuncionalOverlay . $tipoEscopoOverlay;
                $overlays[6] = $tabelaValor($vhCli) . $invTexto;
                $overlays[7] = $prazoTexto;
                break;

            case 'projeto_fechado':
                $overlays[3] = $escopoObjetivo(e($escopoTexto) . '.') . $escopoFuncionalOverlay . $tipoEscopoOverlay;
                $overlays[5] = $tabelaValor($valorProjeto) . $invTexto;
                $overlays[6] = $prazoTexto . $parcelasTexto;
                break;

            case 'bh_fixo':
            default:
                $overlays[3] = $escopoObjetivo('Aquisição de pacote de consultoria de <b style="font-weight:700">' . $horas . ' horas</b> para ' . e($escopoTexto) . '.')
                    . $escopoFuncionalOverlay . $tipoEscopoOverlay;
                $overlays[6] = $tabelaHoras() . $invTexto;
                // PRAZO: DURAÇÃO DO SERVIÇO em meses (col direita x≈685,y224) + textos override + parcelas.
                $overlays[7] = $mask(793, 252, 362, 26, '#f1f1f1')
                    . $frase(796, 256, 356, 'O serviço tem duração prevista de <b style="font-weight:700">' . $dur . ' meses</b>.', 13.5, '#595959')
                    . $prazoTexto . $parcelasTexto;
                break;
        }

        // ACEITE — Dados da Contratada (config global): só sobrepõe se diferir do padrão ERPSERV do deck.
        $defContr = $def['aceite']['contratada'] ?? [];
        if ($contratada && $contratada !== $defContr && array_filter($contratada)) {
            $overlays[$aceiteIdx] = ($overlays[$aceiteIdx] ?? '')
                . $mask(190, 565, 1050, 95, '#cfd5e9')
                . $frase(205, 574, 740, '<b style="font-weight:700">NOME:</b> ' . e($contratada['nome'] ?? ''), 14, '#3a3a3a')
                . $frase(955, 574, 285, '<b style="font-weight:700">CNPJ:</b> ' . e($contratada['cnpj'] ?? ''), 14, '#3a3a3a')
                . $frase(205, 618, 740, '<b style="font-weight:700">ENDEREÇO:</b> ' . e($contratada['endereco'] ?? ''), 14, '#3a3a3a')
                . $frase(955, 618, 285, '<b style="font-weight:700">CEP:</b> ' . e($contratada['cep'] ?? ''), 14, '#3a3a3a');
        }

        return ['codigo' => $p->codigo, 'slides' => $slides, 'overlays' => $overlays];
    }

    /**
     * Preview SEM persistir: monta os slides (URL) + overlays + recalcula a memória de cálculo ao vivo.
     * Usado pelo editor (debounced) p/ refletir edições e o valor pelas regras da planilha.
     */
    public function previewData(array $spec): array
    {
        $tipo   = $spec['tipo'] ?? 'bh_fixo';
        $inputs = (array) ($spec['inputs'] ?? []);
        $modo   = $spec['modo_faturamento'] ?? ($tipo === 'projeto_fechado' ? 'valor_fixo' : 'por_hora');
        $calcOut = $this->calc->compute($inputs, $modo);

        $calc = new CrmProposalCalc(['inputs' => $inputs, 'outputs' => $calcOut, 'modo_faturamento' => $modo]);
        $p = new CrmProposal();
        $p->codigo   = $spec['codigo'] ?? null;
        $p->versao   = (int) ($spec['versao'] ?? 1);
        $p->tipo     = $tipo;
        $p->conteudo = (array) ($spec['conteudo'] ?? []);
        $p->data_emissao = !empty($spec['data_emissao']) ? \Illuminate\Support\Carbon::parse($spec['data_emissao']) : now();
        $p->setRelation('calc', $calc);
        if (!empty($spec['customer_id'])) $p->setRelation('customer', Customer::find($spec['customer_id']));
        if (!empty($spec['vendedor_id'])) $p->setRelation('vendedor', User::find($spec['vendedor_id']));

        $data = $this->buildRenderData($p, 'url');
        return [
            'codigo'   => $p->codigo,
            'calc'     => $calcOut,
            'slides'   => $data['slides'],
            'overlays' => $data['overlays'],
        ];
    }

    /** Textos-padrão do deck por tipo (base do overlay-on-override e do pré-preenchimento do editor). */
    public function proposalDefaults(string $tipo): array
    {
        $aberto = $tipo === 'projeto_fechado' ? 'FECHADO' : 'ABERTO';
        $cardBH = 'Banco de horas fixo para utilização em até 01 ano';
        return [
            'escopo' => [
                'tipo_escopo'      => $aberto,
                'escopo_funcional' => "Nosso objetivo principal é a abertura de um canal para atendimentos, nos moldes de banco de horas fixo, dentro do TOTVS Protheus, Fluig e Power BI, de acordo com as necessidades e alinhamento prévio com a contratante.\n\nAs principais atividades executadas serão:\n• Diagnóstico de ambiente\n• Consultoria de processos\n• Sustentação\n• Manutenção\n• Desenvolvimentos\n• Gerenciamento de Projetos",
            ],
            'investimento' => [
                'card_texto'    => $cardBH,
                'sob_demanda'   => 'Caso a contratante queira utilizar demais serviços da contratada ou ultrapassar as horas contratadas, será cobrado R$ 190,00/hora dentro do horário comercial, com fechamentos mensais e pagamento até dia 10 do mês subsequente.',
                'despesas_sp'   => 'Será cobrado R$170,00 por visita/consultor, para suprir as despesas com alimentação, estacionamento, traslado, combustível, pedágios.',
                'despesas_fora' => 'Será cobrado R$250 por visita/consultor, para suprir as despesas com alimentação, estacionamento, traslado, combustível, pedágios. Despesas como passagem aérea/km e hospedagem deverão ser custeadas pela contratante.',
            ],
            'prazo' => [
                'card_texto'         => $cardBH,
                'inicio_atendimento' => 'O atendimento será iniciado em até 07 dias úteis após a data de assinatura da proposta.',
                'pagamento_despesas' => 'Todas as despesas reembolsáveis serão cobradas via nota de débito no dia 10 do mês posterior ao mês de prestação dos serviços.',
                'parcelas'           => '2x',
                'valor_pct'          => '50% em cada parcela',
                'vencimento'         => '10 / 40 Dias após assinatura da proposta',
            ],
            'aceite' => [
                'contratada' => $this->contratadaPadrao(),
            ],
        ];
    }

    /** Dados da Contratada padrão do deck (ERPSERV). */
    private function contratadaPadrao(): array
    {
        return [
            'nome'     => 'ERPSERV CONSULTORIA DE SISTEMAS LTDA',
            'cnpj'     => '23.870.826/0001-07',
            'endereco' => 'AV. FRANCISCO MATARAZZO, 1752 – CONJ 2607 – AGUA BRANCA - SP/BRASIL',
            'cep'      => '05001-200',
        ];
    }

    /** Config global da Contratada (SystemSetting), com fallback no padrão do deck. */
    public function contratadaConfig(): array
    {
        $raw = \App\Models\SystemSetting::where('key', 'proposta.contratada')->value('value');
        $cfg = $raw ? (json_decode($raw, true) ?: []) : [];
        return array_merge($this->contratadaPadrao(), array_filter($cfg, fn ($v) => $v !== null && $v !== ''));
    }

    /** Resolve a imagem do logo do cliente p/ data-URI (PDF) ou URL (preview). */
    private function resolveLogo(array $conteudo, string $assetMode): ?string
    {
        $attId = $conteudo['logo_attachment_id'] ?? null;
        if (!$attId) return null;
        $att = \App\Models\Attachment::find($attId);
        if (!$att) return null;
        if ($assetMode === 'url') {
            return '/api/v1/crm/proposals/logo/' . $att->id;
        }
        try {
            $bytes = app(\App\Attachments\Storage\StorageProvider::class)->get($att->storage_path);
            $mime  = $att->mime_type ?: 'image/png';
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Proposta = FONTE DA VERDADE do valor: opp.valor = total da proposta ATIVA mais recente
     * (prevalece sobre o Valor Estimado manual). Forecast deriva de opp.valor × probabilidade.
     */
    public function syncOppValor(CrmProposal $p): void
    {
        $opp = $p->opportunity ?: CrmOpportunity::find($p->opportunity_id);
        if (!$opp) return;
        $latest = CrmProposal::where('opportunity_id', $opp->id)
            ->whereNotIn('status', ['cancelada', 'reprovada', 'expirada'])
            ->orderByDesc('id')->first();
        if ($latest) $opp->update(['valor' => $latest->total]);
    }

    private function logEvent(CrmProposal $p, string $type, array $meta, User $actor): void
    {
        $seq = (int) DocumentEvent::where('entity_type', 'CRM_PROPOSAL')->where('entity_id', $p->id)->max('sequence_number') + 1;
        DocumentEvent::create([
            'document_id'     => $meta['document_id'] ?? null,
            'sequence_number' => $seq,
            'event_type'      => $type,
            'codigo'          => $p->codigo,
            'entity_type'     => 'CRM_PROPOSAL',
            'entity_id'       => $p->id,
            'meta'            => $meta,
            'triggered_by'    => $actor->id,
        ]);
    }
}
