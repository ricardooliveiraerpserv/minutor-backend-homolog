<?php

namespace App\Documents;

use App\Models\Contract;
use App\Models\ContractType;
use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityEvent;
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
        'cloud'           => 'pdf.documents.proposta.modulos.banco-horas-mensal',
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

            // Se a oportunidade JÁ tem proposta com código → nova proposta = NOVA VERSÃO (mesmo código + mesmo
            // numero, versao+1; NÃO reserva sequência nova). Primeira proposta da opp → código novo, versão 1.
            $base = CrmProposal::where('opportunity_id', $opp->id)->whereNotNull('codigo')
                ->orderByDesc('versao')->orderByDesc('id')->first();
            $isNewVersion = $base && $base->codigo;
            $versao = $isNewVersion ? ((int) $base->versao + 1) : 1;
            $numero = $isNewVersion ? (int) $base->numero : ((int) CrmProposal::where('opportunity_id', $opp->id)->max('numero') + 1);

            $calc = $this->calc->persist(['versao' => $versao, 'modo_faturamento' => $modo], $spec['inputs']);

            $proposal = CrmProposal::create([
                'opportunity_id' => $opp->id,
                'customer_id'    => $customerId,
                'tipo'           => $spec['tipo'] ?? 'bh_fixo',
                'numero'         => $numero,
                'versao'         => $versao,
                'codigo'         => $isNewVersion ? $base->codigo : null,
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

            // Reserva o código UMA vez (só na 1ª versão; versões seguintes herdam o mesmo código).
            $codigo = $isNewVersion ? $base->codigo : null;
            if (!$isNewVersion) {
                $res = $this->numbers->reservar($customerId, 'proposta', [
                    'entity_type' => 'CRM_PROPOSAL', 'entity_id' => $proposal->id,
                ], $actor->id);
                $proposal->update(['codigo' => $res['codigo']]);
                $codigo = $res['codigo'];
            }

            $this->logEvent($proposal, 'criado', ['codigo' => $codigo, 'versao' => $versao], $actor);
            $this->syncOppValor($proposal);
            // P-E.2.2 — pré-popula validadores/assinantes salvos do cliente (caderno).
            app(\App\Services\ProposalParticipantService::class)->aplicarCaderno($proposal);
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
            app(\App\Services\ProposalParticipantService::class)->aplicarCaderno($nova);
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
            if (!empty($spec['data_emissao'])) $p->data_emissao = $spec['data_emissao']; // data da proposta (capa) editável
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
            // waitForExpression: aguarda o paginador do escopo terminar antes de imprimir.
            'opts'          => ['paperWidth' => 13.333, 'paperHeight' => 7.5, 'preferCssPageSize' => true,
                                'waitForExpression' => 'window.__escopoPaged === true'],
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

    /** Mapa tipo de proposta → tipo_faturamento do contrato. */
    private const TIPO_FAT_MAP = [
        'bh_fixo' => 'banco_horas_fixo', 'bh_mensal' => 'banco_horas_mensal',
        'on_demand' => 'on_demand', 'projeto_fechado' => 'por_servico', 'cloud' => 'saas',
    ];

    /** Melhor-esforço: casa o tipo da proposta com um ContractType cadastrado (vendedor ajusta na revisão). */
    private function matchContractType(string $tipo): ?int
    {
        $pat = ['bh_fixo' => '%fixo%', 'bh_mensal' => '%mensal%', 'on_demand' => '%demand%', 'projeto_fechado' => '%fechado%', 'cloud' => '%cloud%'][$tipo] ?? null;
        if (!$pat) return null;
        return ContractType::where('name', 'ilike', $pat)->value('id');
    }

    /**
     * Gera o CONTRATO a partir da proposta APROVADA, HERDANDO os dados (código comercial, cliente,
     * valor, horas, tipo, escopo, responsável). NÃO consome nova numeração — o contrato (e depois o
     * projeto) compartilham EXATAMENTE o mesmo código (project_code_preview). O contrato nasce como
     * RASCUNHO p/ o vendedor revisar antes de emitir.
     */
    public function converter(CrmProposal $p, array $spec, User $actor): Contract
    {
        $p->loadMissing(['calc', 'customer', 'vendedor', 'opportunity', 'document']);

        // Já convertido? Devolve o contrato existente (idempotente) — pelo vínculo crm_proposal_id ou pela oportunidade.
        if (in_array($p->status, ['contrato_gerado', 'assinatura_pendente', 'assinado', 'convertida'], true)) {
            if ($existente = Contract::where('crm_proposal_id', $p->id)->orderByDesc('id')->first()) return $existente;
            if ($p->opportunity?->contract_id && ($existente = Contract::find($p->opportunity->contract_id))) return $existente;
        }
        // Handoff Proposal-Centric: o contrato operacional nasce a partir da proposta LIBERADA
        // (fim comercial). Mantém 'aprovada'/'contrato_gerado' por retrocompatibilidade.
        if (!in_array($p->status, ['liberada', 'aprovada', 'contrato_gerado'], true)) {
            throw new \RuntimeException('Só propostas LIBERADAS podem gerar o contrato operacional.');
        }
        // P-E.2.2 — se há assinantes definidos, a assinatura precisa estar concluída pelo modo configurado.
        $p->loadMissing('participants');
        $signers = $p->participants->where('is_active', true)->filter(fn ($x) => $x->hasRole('signer'));
        if ($signers->isNotEmpty() && !app(\App\Services\ProposalParticipantService::class)->assinaturaCompleta($p)) {
            throw new \RuntimeException('Assinatura ainda não concluída — todas as regras de assinatura (' . ($p->assinatura_modo === 'um_por_parte' ? 'ao menos um assinante por parte' : 'todos os assinantes') . ') precisam ser atendidas antes de converter.');
        }

        $customer = $p->customer ?: Customer::find($p->customer_id);
        $inputs   = (array) ($p->calc?->inputs ?? []);
        $outputs  = (array) ($p->calc?->outputs ?? []);
        $conteudo = (array) ($p->conteudo ?? []);
        $tipo     = $p->tipo ?: 'bh_fixo';
        $isFixo   = in_array($tipo, ['on_demand', 'projeto_fechado'], true);

        $horas      = (int) ($inputs['horas_consultoria'] ?? 0);
        $valorHora  = (float) ($inputs['venda_h'] ?? $inputs['valor_hora_cliente'] ?? 0);
        $coordHoras = (int) ($outputs['coordenacao_horas'] ?? 0);
        $pctCoord   = (float) ($inputs['params']['pct_coordenacao'] ?? 0.20) * 100;
        $escopo     = trim((string) ($conteudo['escopo']['objetivo'] ?? $inputs['escopo_texto'] ?? ''));

        return DB::transaction(function () use ($p, $spec, $actor, $customer, $tipo, $isFixo, $horas, $valorHora, $coordHoras, $pctCoord, $escopo) {
            $contract = Contract::create([
                'customer_id'          => $p->customer_id,
                'project_name'         => $p->codigo . ' — ' . (optional($p->opportunity)->title ?: ($customer?->name ?? '')),
                'categoria'            => $spec['categoria'] ?? ($tipo === 'cloud' ? 'sustentacao' : 'projeto'),
                'contract_type_id'     => $spec['contract_type_id'] ?? $this->matchContractType($tipo),
                'service_type_id'      => $spec['service_type_id'] ?? null,
                'tipo_faturamento'     => $spec['tipo_faturamento'] ?? (self::TIPO_FAT_MAP[$tipo] ?? null),
                'horas_contratadas'    => $isFixo ? 0 : $horas,
                'horas_consultor'      => $isFixo ? null : ($horas ?: null),
                'pct_horas_coordenador' => $coordHoras ? $pctCoord : null,
                'horas_coordenacao'    => $coordHoras ?: null,
                'valor_projeto'        => (float) $p->total ?: (float) $p->valor,
                'valor_hora'           => $valorHora ?: null,
                'project_code_preview' => $p->codigo,   // HERANÇA do código comercial — sem nova sequência
                'observacoes'          => $escopo ?: null,
                // Rastreabilidade + CONGELAMENTO da origem (Itens 2+3): proposta, versão, documento e memória.
                'crm_proposal_id'           => $p->id,
                'proposal_version'          => $p->versao,
                'proposal_document_id'      => $p->document_id,
                'proposal_document_version' => $p->document?->versao,
                'proposal_document_hash'    => $p->document?->hash,
                'proposal_calc_snapshot'    => [
                    'inputs'    => (array) ($p->calc?->inputs ?? []),
                    'outputs'   => (array) ($p->calc?->outputs ?? []),
                    'modo'      => $p->calc?->modo_faturamento,
                    'frozen_at' => now()->toIso8601String(),
                ],
                'executivo_conta_id'   => $customer?->executive_id,
                'vendedor_id'          => $p->vendedor_id,
                'status'               => Contract::STATUS_RASCUNHO,
                'kanban_status'        => Contract::KANBAN_BACKLOG,
                'created_by_id'        => $actor->id,
            ]);

            if ($p->opportunity && !$p->opportunity->contract_id) {
                $p->opportunity->update(['contract_id' => $contract->id]);
            }
            // Terminal do handoff: proposta CONVERTIDA (mesmo código, vínculo crm_proposal_id no contrato).
            $p->update(['status' => 'convertida']);

            // Auditoria da PROPOSTA (entity CRM_PROPOSAL, sem document_id p/ não duplicar na timeline do Portal).
            $this->logEvent($p, 'contrato_gerado', ['contract_id' => $contract->id, 'codigo' => $p->codigo], $actor);
            // Evento no DOCUMENTO (artefato) — aparece na timeline de engajamento.
            $p->document?->logEvent(DocumentEvent::TYPE_CONTRATO_GERADO, ['contract_id' => $contract->id], $actor->id);
            // Marco na timeline COMERCIAL da oportunidade.
            if ($p->opportunity_id) {
                CrmOpportunityEvent::log((int) $p->opportunity_id, 'note', [
                    'to_value' => "Proposta {$p->codigo} CONVERTIDA — contrato operacional criado no Kanban de Serviços",
                    'meta'     => ['kind' => 'proposta_convertida', 'contract_id' => $contract->id],
                ]);
            }
            return $contract;
        });
    }

    /**
     * Payload do render orientado a ARTWORK: slides SVG originais + overlays dinâmicos.
     * Os slides estáticos (problemas/soluções/processos/suporte/aceite/obrigado) = artwork puro.
     * Os dinâmicos (capa/escopo/investimento/prazo) recebem overlay posicionado (cobre placeholder
     * com a cor exata do fundo + escreve o valor real). Mecanismo validado na capa.
     */
    /**
     * Variáveis de template {chave} — FONTE ÚNICA usada tanto pelo PDF (buildRenderData) quanto pelo
     * Portal HTML (ProposalSectionService). Garante que o conteúdo interpolado seja IDÊNTICO nos dois.
     */
    public function templateVars(CrmProposal $p): array
    {
        $c = $p->relationLoaded('calc') ? $p->calc : $p->calc()->first();
        $inputs = (array) ($c->inputs ?? []);
        $tipo   = $p->tipo ?: 'bh_fixo';
        $brlV   = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');

        $horas        = (int) ($inputs['horas_consultoria'] ?? 0);
        $vhCli        = (float) ($inputs['valor_hora_cliente'] ?? $inputs['venda_h'] ?? 0);
        $total        = $horas * $vhCli;
        $dur          = (int) ($inputs['duracao_meses'] ?? 12);
        $valorProjeto = (float) ($inputs['valor_projeto'] ?? $inputs['valor_fixo'] ?? $total);
        $escopoTexto  = trim((string) ($inputs['escopo_texto'] ?? '')) ?: 'serviços especializados em ERP Protheus, Infraestrutura e Power BI';
        $cliente      = optional($p->customer)->name ?? '—';
        $cnpj         = optional($p->customer)->cgc ?? '—';
        $exec         = optional($p->vendedor)->name ?? '—';
        $data         = optional($p->data_emissao)->format('d/m/Y') ?? now()->format('d/m/Y');
        $tipoLabel    = [
            'bh_fixo' => 'Banco de Horas Fixo', 'bh_mensal' => 'Banco de Horas Mensal',
            'on_demand' => 'On Demand', 'projeto_fechado' => 'Projeto Fechado', 'cloud' => 'Cloud Protheus',
        ][$tipo] ?? $tipo;
        $coordHoras   = (int) ceil($horas * 0.20);

        return [
            'cliente' => $cliente, 'cnpj' => $cnpj, 'executivo' => $exec, 'vendedor' => $exec,
            'data' => $data, 'codigo' => (string) ($p->codigo ?? ''), 'versao' => str_pad((string) ($p->versao ?? 1), 2, '0', STR_PAD_LEFT),
            'tipo' => $tipoLabel,
            'horas' => (string) $horas, 'horas_coordenacao' => (string) $coordHoras,
            'valor_hora' => $brlV($vhCli), 'valor' => $brlV($total), 'valor_total' => $brlV($total),
            'valor_projeto' => $brlV($valorProjeto), 'duracao' => (string) $dur, 'duracao_meses' => (string) $dur,
            'servico' => $escopoTexto, 'escopo_texto' => $escopoTexto,
        ];
    }

    /** Interpola {chave} num texto usando templateVars() — tags desconhecidas ficam intactas. */
    public function fillTags(CrmProposal $p, string $txt): string
    {
        $vars = $this->templateVars($p);
        return (string) preg_replace_callback('/\{([a-zA-Z_]+)\}/', fn ($m) => $vars[strtolower($m[1])] ?? $m[0], $txt);
    }

    /**
     * P-E.2.4 — MANIFESTO de assinaturas (estilo Clicksign) estampado no corpo da proposta:
     * cabeçalho (código + hash), bloco "Assinaturas" (✓ nome/CPF/como/quando) e "Log" de auditoria + validade jurídica.
     */
    private function assinaturaManifesto(CrmProposal $p): array
    {
        $rotulo = ['contratada' => 'contratada', 'contratante' => 'contratante', 'indefinida' => 'assinante'];
        $ativos = $p->participants()->where('is_active', true)->get();
        $assinados = $ativos->filter(fn ($x) => $x->hasRole('signer') && $x->signed_at !== null)
            ->sortBy(fn ($x) => [$x->parte ?: 'z', (string) $x->signed_at]);
        if ($assinados->isEmpty()) return [];

        $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') . ' às ' . \Illuminate\Support\Carbon::parse($d)->format('H:i:s') : '';
        $assinaturas = $assinados->map(fn ($x) => [
            'nome' => $x->sign_name ?: $x->name,
            'cpf' => $x->sign_cpf,
            'como' => $rotulo[$x->parte ?: 'indefinida'] ?? 'assinante',
            'data' => $fmt($x->signed_at),
            'image' => (filled($x->sign_image) && str_starts_with((string) $x->sign_image, 'data:image/')) ? $x->sign_image : null,
        ])->values()->all();

        // LOG de auditoria (ordem cronológica): aprovações + assinaturas (com pontos de autenticação) + conclusão.
        $log = [];
        foreach ($ativos as $x) {
            if ($x->approved_at) $log[] = ['ts' => $x->approved_at, 'texto' => "{$x->name} APROVOU a proposta." . ($x->approval_comment ? " Comentário: \"{$x->approval_comment}\"." : '')];
            if ($x->signed_at) {
                $metodo = $x->sign_image ? 'Assinatura eletrônica (traço) via Minutor' : 'Aceite eletrônico via Minutor';
                $pts = 'Pontos de autenticação: e-mail ' . $x->email . ($x->sign_cpf ? '; CPF informado: ' . $x->sign_cpf : '') . ($x->sign_ip ? '; IP: ' . $x->sign_ip : '') . '.';
                $log[] = ['ts' => $x->signed_at, 'texto' => "{$x->name} assinou como {$rotulo[$x->parte ?: 'indefinida']}. {$pts} {$metodo}."];
            }
        }
        usort($log, fn ($a, $b) => (string) $a['ts'] <=> (string) $b['ts']);
        $ultima = $assinados->max('signed_at');
        if (in_array($p->status, ['assinada', 'liberada', 'convertida'], true) && $ultima) {
            $log[] = ['ts' => $ultima, 'texto' => 'Processo de assinatura concluído — proposta ' . $p->codigo . ' formalizada.'];
        }
        $log = array_map(fn ($e) => ['data' => $fmt($e['ts']), 'texto' => $e['texto']], $log);

        $hash = $assinados->first()->sign_doc_hash ?: optional($p->document)->hash;
        return [
            'codigo' => $p->codigo,
            'hash' => $hash,
            'gerado_em' => \Illuminate\Support\Carbon::now()->format('d/m/Y H:i'),
            'assinaturas' => $assinaturas,
            'log' => $log,
        ];
    }

    /**
     * P-E.2.4 — Sincroniza a assinatura com o Clicksign (sem webhook): marca quem assinou (via eventos),
     * finaliza/captura quando concluído e REGENERA o PDF com a página de registro. Reutilizado pelo editor e pelo portal.
     */
    public function sincronizarClicksign(CrmProposal $p, ?\App\Models\User $actor = null): array
    {
        $cs = app(\App\Services\Clicksign\ClicksignService::class);
        $env = \App\Models\ClicksignEnvelope::with('signers')->where('crm_proposal_id', $p->id)->orderByDesc('id')->first();
        if (!$env) return ['ok' => false, 'erro' => 'Nenhum envelope de assinatura.', 'status' => $p->status, 'assinada' => false];
        if ($cs->usandoStub()) return ['ok' => false, 'erro' => 'Clicksign em simulação.', 'status' => $p->status, 'assinada' => false, 'stub' => true];
        try { $st = $cs->statusEnvelope($env); } catch (\Throwable $e) { return ['ok' => false, 'erro' => 'Falha ao consultar o Clicksign: ' . $e->getMessage(), 'status' => $p->status, 'assinada' => false]; }

        $parts = app(\App\Services\ProposalParticipantService::class);
        foreach ($st['signers'] as $s) {
            $signer = $env->signers->firstWhere('clicksign_signer_id', $s['clicksign_signer_id']);
            $assinou = in_array(strtolower((string) ($s['status'] ?? '')), ['signed', 'finished', 'closed'], true) || !empty($s['signed_at']);
            if ($signer && $assinou && $signer->crm_proposal_participant_id) {
                $part = $p->participants()->find($signer->crm_proposal_participant_id);
                if ($part && !$part->signed_at) $parts->marcarAssinouViaClicksign($part, $signer, $p);
            }
        }
        $finalizou = in_array(strtolower((string) $st['status']), ['finished', 'closed', 'completed'], true);
        if ($finalizou && $env->is_active) {
            $env->update(['status' => \App\Models\ClicksignEnvelope::STATUS_FINISHED, 'finished_at' => now(), 'is_active' => false, 'capture_status' => \App\Models\ClicksignEnvelope::CAP_PENDENTE]);
            try { \App\Jobs\CaptureSignedDocumentJob::dispatchSync($env->id); } catch (\Throwable $e) { \Log::warning('[sync] captura falhou: ' . $e->getMessage()); }
        }
        $assinada = $p->fresh()->status === 'assinada';
        if ($assinada) {
            try {
                $actor = $actor ?: ($p->vendedor ?: ($p->created_by_id ? \App\Models\User::find($p->created_by_id) : null) ?: \App\Models\User::where('type', 'admin')->first());
                if ($actor) $this->gerarDocumento($p->fresh(), $actor, true); // regenera com a página de registro
            } catch (\Throwable $e) { \Log::warning('[sync] regen PDF com registro falhou: ' . $e->getMessage()); }
        }
        return ['ok' => true, 'status' => $p->fresh()->status, 'assinada' => $assinada, 'envelope_status' => $st['status'], 'finalizou' => $finalizou];
    }

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
        $slideCount = $tipo === 'cloud' ? 13 : ($tipo === 'projeto_fechado' ? 9 : 10);
        $slides = [];
        for ($i = 1; $i <= $slideCount; $i++) {
            if ($u = $asset(sprintf('slides/%s/slide-%02d.png', $tipo, $i))) $slides[] = $u;
        }
        if (empty($slides)) {
            for ($i = 1; $i <= 10; $i++) {
                if ($u = $asset(sprintf('slides/bh_fixo/slide-%02d.png', $i))) $slides[] = $u;
            }
        }
        // CLOUD: a CAPA é a MESMA dos demais tipos (template limpo) — só o tipo de contrato (subtítulo)
        // muda p/ "CLOUD PROTHEUS". O slide-01 do deck cloud tinha dados de um cliente baked; não usamos.
        if ($tipo === 'cloud' && !empty($slides) && ($capa = $asset('slides/bh_mensal/slide-01.png'))) {
            $slides[0] = $capa;
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

        // ===== TAGS {chave} — FONTE ÚNICA (templateVars), compartilhada com o Portal HTML.
        $vars = $this->templateVars($p);
        $fillVars = fn (string $txt) => preg_replace_callback('/\{([a-zA-Z_]+)\}/', fn ($m) => $vars[strtolower($m[1])] ?? $m[0], $txt);

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
            // posição/tamanho ajustáveis (conteudo.logo). offset_x: esq(-)/dir(+); offset_y: cima(-)/baixo(+); escala: % do box.
            $ox  = (int) (data_get($conteudo, 'logo.offset_x') ?? 0);
            $oy  = (int) (data_get($conteudo, 'logo.offset_y') ?? 0);
            $esc = max(40, min(220, (int) (data_get($conteudo, 'logo.escala') ?? 100)));
            $lw  = (int) round(268 * $esc / 100);
            $lh  = (int) round(140 * $esc / 100);
            $lx  = 1114 + $ox - intdiv($lw, 2);    // centro X do box do deck (980+134) + deslocamento horizontal
            $ly  = 476 + $oy - intdiv($lh, 2);     // centro Y do box (406+70) + deslocamento vertical
            $overlays[0] .= $mask(974, 400, 280, 152, $BG)
                . '<div style="position:absolute;left:' . $lx . 'px;top:' . $ly . 'px;width:' . $lw . 'px;height:' . $lh . 'px;display:flex;align-items:center;justify-content:center">'
                . '<img src="' . $logo . '" style="max-width:100%;max-height:100%;object-fit:contain"></div>';
        }

        // ===== CLOUD PROTHEUS — layout próprio (13 slides): renderiza o artwork do deck + overlay da capa
        // + tabelas configuráveis (SLA/Investimento) por proposta. NÃO usa as páginas HTML de escopo/aceite. =====
        if ($tipo === 'cloud') {
            // ===== CAPA — subtítulo (tipo de contrato): a capa base é a dos demais tipos (template bh_mensal,
            // baked "BANCO DE HORAS MENSAL"); aqui mascaramos e escrevemos o tipo do cloud. Editável via capa_nome. =====
            $capaNome = trim((string) data_get($conteudo, 'cloud.capa_nome', '')) ?: 'CLOUD PROTHEUS';
            $overlays[0] .= $mask(172, 340, 520, 42, '#442B7E')
                . '<div style="position:absolute;left:190px;top:349px;font-family:\'Roboto Condensed\',Arial,sans-serif;font-weight:300;font-size:21px;letter-spacing:1px;color:#c9c4dd">' . e(mb_strtoupper($capaNome)) . '</div>';

            // Helper p/ tabela de INVESTIMENTO (linhas + desconto + total) — reusado p/ Único e Mensal.
            $investTable = function (string $key, string $totalLabel, int $top) use ($conteudo, $brl, $mask) {
                $iv = (array) data_get($conteudo, "cloud.$key", []);
                $dLabel = trim((string) data_get($iv, 'desconto.label', '')) ?: 'Desconto';
                $dVal = (float) data_get($iv, 'desconto.valor', 0);
                // VALOR de cada linha = faturamento da MEMÓRIA DE CÁLCULO (custo/margem) − desconto da linha.
                // MENSAL (investimento): MULTI-LINHA — 1 card/tipo de contrato no Kanban. ÚNICO: single.
                $fatDe = function ($m, $fallback = 0.0) {
                    if (is_array($m) && ((float) data_get($m, 'horas_consultoria', 0) > 0 || (float) data_get($m, 'venda_h', 0) > 0)) {
                        return (float) (new CrmProposalCalcService())->compute($m, 'por_hora')['faturamento'];
                    }
                    return (float) $fallback;
                };
                $mensalLinhas = data_get($iv, 'linhas');
                if ($key === 'investimento' && is_array($mensalLinhas) && count($mensalLinhas)) {
                    $lin = [];
                    foreach ($mensalLinhas as $ln) {
                        $val = $fatDe(data_get($ln, 'memoria', []), (float) data_get($ln, 'valor', 0)) - (float) data_get($ln, 'desconto.valor', 0);
                        $lin[] = ['label' => (trim((string) data_get($ln, 'label', '')) ?: 'CLOUD'), 'valor' => $val];
                    }
                    $dVal = 0.0; // desconto já embutido por linha
                } else {
                    $mem = data_get($iv, 'memoria');
                    if (is_array($mem) && ((float) data_get($mem, 'horas_consultoria', 0) > 0 || (float) data_get($mem, 'venda_h', 0) > 0)) {
                        $lin = [['label' => (trim((string) data_get($iv, 'label', '')) ?: 'CLOUD'), 'valor' => $fatDe($mem)]];
                    } else {
                        $lin = data_get($iv, 'linhas');
                        if (!is_array($lin) || count($lin) === 0) $lin = [['label' => 'CLOUD', 'valor' => 0]];
                    }
                }
                // base de cálculo por linha: quantidade × valor unitário (fallback ao 'valor' legado).
                $lineVal = function ($l) {
                    $q = $l['quantidade'] ?? null;
                    $u = $l['valor_unitario'] ?? null;
                    if (($q !== null && $q !== '') || ($u !== null && $u !== '')) return (float) $q * (float) $u;
                    return (float) ($l['valor'] ?? 0);
                };
                $s = 0.0;
                foreach ($lin as $l) $s += $lineVal($l);
                $tot = $s - $dVal;
                $rows = '';
                foreach (array_values($lin) as $i => $l) {
                    $bg = $i % 2 === 0 ? '#cfd5e9' : '#e8ebf4';
                    $v = $brl($lineVal($l));
                    $rows .= '<div style="display:flex;align-items:center;min-height:34px;background:' . $bg . ';color:#33335a;font-size:12.5px">'
                        . '<div style="width:290px;padding:5px 12px;font-weight:700">' . e((string) ($l['label'] ?? '')) . '</div>'
                        . '<div style="width:248px;text-align:center">' . $v . '</div><div style="width:310px;text-align:center">' . $v . '</div></div>';
                }
                if ($dVal > 0) $rows .= '<div style="display:flex;align-items:center;min-height:30px;background:#e8ebf4;color:#33335a;font-size:12.5px">'
                    . '<div style="width:538px;padding:4px 12px;font-weight:700">' . e($dLabel) . '</div>'
                    . '<div style="width:310px;text-align:center;color:#c0392b;font-weight:700">&minus; ' . $brl($dVal) . '</div></div>';
                // Geometria amarrada à tabela BAKED do deck (left≈363, width≈848, topo≈177): mascaramos a tabela
                // do slide inteira (até x≈1212 / y≈315) e redesenhamos com os valores dinâmicos por cima.
                $t = '<div style="position:absolute;left:363px;top:' . $top . 'px;width:848px;font-family:\'Roboto Condensed\',Arial,sans-serif">'
                    . '<div style="display:flex;background:#442B7E;color:#fff;font-weight:700;font-size:13px;letter-spacing:1px">'
                    .   '<div style="width:290px;text-align:center;padding:5px 0">HORAS</div><div style="width:248px;text-align:center;padding:5px 0">VALOR</div>'
                    .   '<div style="width:310px;text-align:center;padding:5px 0">SUBTOTAL</div></div>' . $rows
                    . '<div style="display:flex;align-items:center;min-height:38px">'
                    .   '<div style="width:538px;background:#cfd5e9;text-align:right;padding:0 16px;font-weight:700;color:#33335a;font-size:14px;line-height:38px">' . $totalLabel . '</div>'
                    .   '<div style="width:310px;background:#10AAA5;text-align:center;color:#fff;font-weight:800;font-size:16px;line-height:38px">' . $brl($tot) . '</div></div></div>';
                // mask cobre a tabela baked INTEIRA dos dois slides — medido no RENDER: Mensal (2 linhas + total)
                // tem a linha total baked até y≈395, teal até x≈1220, barra roxa esq. em x≈349; despesas baked ≈405.
                return $mask(342, $top - 5, 882, 226, '#f7f8fb') . $t;
            };
            // INVESTIMENTO ÚNICO (s10, idx 9) + MENSAL (s11, idx 10). topo alinhado ao header baked (~177).
            $overlays[9]  = ($overlays[9] ?? '')  . $investTable('investimento_unico', 'TOTAL', 177);
            $overlays[10] = ($overlays[10] ?? '') . $investTable('investimento', 'TOTAL/MENSAL', 177);

            // ===== ESCOPO no PADRÃO #69 + TODAS as tabelas FLUINDO na mesma página (mesmo layout escopo-cont).
            // O deck tem o escopo/tabelas em páginas "O QUE VAMOS FAZER" (idx 3-7); são PULADAS e o conteúdo
            // (texto funcional + Horário + SLA + Estrutura + Severidade) flui no data-escopo-box, paginando p/ #69.
            $escTipo = data_get($conteudo, 'escopo.tipo_escopo') ?: 'FECHADO';
            $objOver = trim((string) data_get($conteudo, 'escopo.objetivo'));
            $objTxt  = $objOver !== '' ? nl2br(e($fillVars($objOver))) : 'Contratação de serviço de disponibilidade em cloud do ERP PROTHEUS 12.';
            $temEscopoProprio = (is_array(data_get($conteudo, 'escopo.blocks')) && count(data_get($conteudo, 'escopo.blocks')))
                || trim((string) data_get($conteudo, 'escopo.escopo_funcional')) !== '';
            if ($temEscopoProprio) {
                $escFunc = $this->escopoBlocksHtml($conteudo, $def, $assetMode, $vars);
            } else {
                // Texto-padrão do escopo Cloud (espelha as 3 páginas do deck: Serviços ERP / CLOUD / Equipe).
                $cloudEsc = [
                    ['p', 'Abaixo é possível verificar os principais serviços contemplados em nosso escopo:'],
                    ['gap', ''],
                    ['h', 'Serviços ERP:'],
                    ['i', 'Padronização e gestão de estrutura do ambiente ERP.'],
                    ['i', 'Armazenamento do ERP PROTHEUS 12. (Ambiente produção e teste).'],
                    ['i', 'Gestão de credenciais de perfil administrador (usuários e senhas)'],
                    ['i', 'Liberação de acessos somente ao ERP PROTHEUS 12 (funcionários e terceiros).'],
                    ['i', 'Administração de banco de dados.'],
                    ['i', 'Gerenciamento de serviços das aplicações no sistema operacional.'],
                    ['i', 'Gestão de aplicações TOTVS (Web Service, TSS, TopConnect, Rest etc).'],
                    ['i', 'Disponibilização de 2 ambientes para uso (PRODUÇÃO E QA).'],
                    ['i', 'Banco de horas com 20 horas mensais para sustentação em Protheus'],
                    ['i', 'Migração do ERP atual para Cloud da ERPSERV.'],
                    ['gap', ''],
                    ['h', 'Serviços CLOUD:'],
                    ['i', 'Storage para hospedagem de banco de dados e aplicações.'],
                    ['i', 'Licenciamento de softwares: Windows Server Data Center, Agente de Backup Corporativo, Antivírus Corporativo, Firewall, Monitoramento Corporativo, SQL Server Standard.'],
                    ['i', 'Conectividade: Banda de Internet Compartilhada (Mbps), IP Público IPv4'],
                    ['i', 'Serviços: Suporte servidor, backup periódico do banco de dados, monitoramento de ambiente (servidores, devices e network).'],
                    ['i', 'Segurança e backup das informações (Antivírus e Firewall).'],
                    ['i', 'Licenciamento SQL SERVER'],
                    ['gap', ''],
                    ['h', 'Equipe de Atendimento:'],
                    ['p', 'Nossa equipe de atendimento é composta pela seguinte estrutura para a realização da abertura de chamados de incidentes, solicitações, reclamações e sugestões'],
                    ['gap', ''],
                    ['h', 'A) Time de Direcionadores'],
                    ['p', 'Time responsável em classificar a criticidade, registrar o chamado e em encaminhar para o time solucionador (responsável).'],
                    ['gap', ''],
                    ['h', 'B) Time de Suporte (Grupos Solucionadores):'],
                    ['b', '1º Nível'], ['b', '2º Nível'], ['b', '3º Nível'],
                    ['gap', ''],
                    ['h', 'C) Time de Suporte (Grupos Solucionadores):'],
                    ['b', 'Microsoft Windows'], ['b', 'Banco de dados (SQL Server)'], ['b', 'Backup.'], ['b', 'Antivírus.'], ['b', 'Monitoramento'],
                ];
                $escFunc = '';
                foreach ($cloudEsc as [$t, $txt]) {
                    if ($t === 'gap') { $escFunc .= '<div class="eb-gap"></div>'; continue; }
                    if ($t === 'h')   { $escFunc .= '<div class="eb-line" style="font-weight:700;color:#442B7E;margin:8px 0 3px;font-size:17px">' . e($txt) . '</div>'; continue; }
                    if ($t === 'i')   { $escFunc .= '<div class="eb-line">&#10003; ' . e($txt) . '</div>'; continue; }
                    if ($t === 'b')   { $escFunc .= '<div class="eb-line">&bull; ' . e($txt) . '</div>'; continue; }
                    $escFunc .= '<div class="eb-line">' . e($txt) . '</div>';
                }
            }

            // ===== Tabelas como BLOCOS que fluem no data-escopo-box (largura ~1080) — paginam no MESMO layout #69. =====
            $tblBloco = function (string $titulo, string $inner, string $hl = '') {
                $h = $titulo !== '' ? '<div style="font-weight:700;color:#442B7E;font-size:18px;margin:6px 0 6px">' . e($titulo) . '</div>' : '';
                return '<div class="eb-img"' . ($hl !== '' ? ' data-hl="' . $hl . '"' : '') . ' style="margin:14px 0 4px">' . $h
                    . '<div style="font-family:\'Roboto Condensed\',Arial,sans-serif;font-size:12.5px;color:#33335a">' . $inner . '</div></div>';
            };
            $hor = data_get($conteudo, 'cloud.horario.linhas');
            if (!is_array($hor) || count($hor) === 0) $hor = [
                ['servico' => 'Serviços de armazenamento', 'horario' => '24 x 7', 'obs' => ''],
                ['servico' => 'Monitoração de ambientes', 'horario' => '24 x 7', 'obs' => ''],
                ['servico' => 'Abertura de chamados junto a ERPSERV.', 'horario' => '24 x 7', 'obs' => ''],
                ['servico' => 'Gestão de incidentes', 'horario' => '24 x 7', 'obs' => ''],
                ['servico' => 'Gestão de problemas', 'horario' => '8 x 5', 'obs' => ''],
                ['servico' => 'Gestão de mudanças', 'horario' => '8 x 5', 'obs' => 'Exceto execução que deverá ser 24 x 7'],
                ['servico' => 'Projetos, propostas e solicitações', 'horario' => '8 x 5', 'obs' => 'Exceto execução acordada entre as partes'],
            ];
            $horRows = '<div style="display:flex;background:#442B7E;color:#fff;font-weight:700"><div style="width:430px;padding:8px 12px">Serviço</div><div style="width:240px;padding:8px 4px;text-align:center">Horário de Atendimento</div><div style="width:410px;padding:8px 12px">OBS.</div></div>';
            foreach (array_values($hor) as $i => $r) {
                $bg = $i % 2 === 0 ? '#cfd5e9' : '#e8ebf4';
                $horRows .= '<div style="display:flex;background:' . $bg . '"><div style="width:430px;padding:7px 12px;line-height:1.25">' . nl2br(e((string) ($r['servico'] ?? ''))) . '</div><div style="width:240px;text-align:center;padding:7px 4px">' . e((string) ($r['horario'] ?? '')) . '</div><div style="width:410px;padding:7px 12px;line-height:1.2">' . nl2br(e((string) ($r['obs'] ?? ''))) . '</div></div>';
            }
            $sla = data_get($conteudo, 'cloud.sla.linhas');
            if (!is_array($sla) || count($sla) === 0) $sla = [
                ['indicador' => "Disponibilidade da Infraestrutura Data Center\nErpserv (energia, ar condicionado, nobreak, gerador)", 'nivel' => '99,9%', 'horario' => '24x7x365'],
                ['indicador' => 'Disponibilidade da Solução Private Cloud', 'nivel' => '99,5%', 'horario' => '24x7x365'],
                ['indicador' => "Disponibilidade da Solução Acesso Seguro (Firewall Virtual Data Center)", 'nivel' => '99,5%', 'horario' => '24x7x365'],
                ['indicador' => 'Disponibilidade da Solução Acesso Seguro (On-Premise)', 'nivel' => '98,0%', 'horario' => '24x7x365'],
                ['indicador' => 'Disponibilidade da Solução Suporte Gerenciado', 'nivel' => 'N/C', 'horario' => '24x7x365'],
            ];
            $slaRows = '<div style="display:flex;background:#442B7E;color:#fff;font-weight:700"><div style="width:400px;padding:8px 12px">INDICADOR</div><div style="width:280px;padding:8px 4px;text-align:center">NÍVEL DE SERVIÇO CONTRATADO %</div><div style="width:400px;padding:8px 4px;text-align:center">HORÁRIO DE COBERTURA</div></div>';
            foreach (array_values($sla) as $i => $r) {
                $bg = $i % 2 === 0 ? '#cfd5e9' : '#e8ebf4';
                $slaRows .= '<div style="display:flex;background:' . $bg . '"><div style="width:400px;padding:7px 12px;line-height:1.25">' . nl2br(e((string) ($r['indicador'] ?? ''))) . '</div><div style="width:280px;text-align:center;padding:7px 4px">' . e((string) ($r['nivel'] ?? '')) . '</div><div style="width:400px;text-align:center;padding:7px 4px">' . e((string) ($r['horario'] ?? '')) . '</div></div>';
            }
            $estr = data_get($conteudo, 'cloud.estrutura.linhas');
            if (!is_array($estr) || count($estr) === 0) $estr = [['nome' => 'NOC (Network Operation Center):', 'descricao' => 'Time de Monitoração – Responsável pela verificação pró ativa do ambiente e o atendimento dos contatos de clientes fora do horário comercial.']];
            $estrRows = '<div style="display:flex;background:#442B7E;color:#fff;font-weight:700"><div style="width:360px;padding:8px 12px">Estrutura</div><div style="width:720px;padding:8px 12px">Descrição</div></div>';
            foreach (array_values($estr) as $i => $r) {
                $bg = $i % 2 === 0 ? '#cfd5e9' : '#e8ebf4';
                $estrRows .= '<div style="display:flex;background:' . $bg . '"><div style="width:360px;padding:8px 12px;font-weight:700">' . nl2br(e((string) ($r['nome'] ?? ''))) . '</div><div style="width:720px;padding:8px 12px;line-height:1.3">' . nl2br(e((string) ($r['descricao'] ?? ''))) . '</div></div>';
            }
            $sev = data_get($conteudo, 'cloud.severidade.linhas');
            if (!is_array($sev) || count($sev) === 0) $sev = [
                ['severidade' => '1', 'impacto' => 'Indisponibilidade', 'reacao' => 'Em até 15 Minutos', 'solucao' => 'Em até 04 horas'],
                ['severidade' => '2', 'impacto' => 'Sem Impacto ao negócio', 'reacao' => 'Em até 20 Minutos', 'solucao' => 'N/A'],
            ];
            $sevRows = '<div style="display:flex;background:#442B7E;color:#fff;font-weight:700"><div style="width:240px;padding:8px 12px">Severidade</div><div style="width:270px;padding:8px 12px">Impacto ao Negócio</div><div style="width:270px;padding:8px 12px">SLA de Reação</div><div style="width:300px;padding:8px 12px">SLA de Solução</div></div>';
            foreach (array_values($sev) as $i => $r) {
                $bg = $i % 2 === 0 ? '#cfd5e9' : '#e8ebf4';
                $sevRows .= '<div style="display:flex;background:' . $bg . '"><div style="width:240px;text-align:center;padding:7px 4px">' . e((string) ($r['severidade'] ?? '')) . '</div><div style="width:270px;padding:7px 12px">' . e((string) ($r['impacto'] ?? '')) . '</div><div style="width:270px;padding:7px 12px">' . e((string) ($r['reacao'] ?? '')) . '</div><div style="width:300px;padding:7px 12px">' . e((string) ($r['solucao'] ?? '')) . '</div></div>';
            }
            $tabelasHtml = $tblBloco('Horário de Atendimento', $horRows, 'horario')
                . $tblBloco('Nível de Serviço acordado', $slaRows, 'sla')
                . $tblBloco('Estrutura de atendimento e SLA', $estrRows . '<div style="height:10px"></div>' . $sevRows, 'estrutura');

            $lblObjC = 'display:inline-block;background:#442B7E;color:#fff;font-size:14px;font-weight:700;letter-spacing:1.5px;padding:4px 14px;border-radius:5px;margin-bottom:8px';
            $lblC    = 'font-size:11px;font-weight:700;letter-spacing:1px;color:#442B7E;margin-bottom:3px';
            $cloudEscopo = '<div class="slide escopo-cont"><div class="ec-head"><div class="ec-title">ESCOPO</div></div><div class="ec-rule"></div>'
                . '<div data-hl="escopo" style="position:absolute;left:90px;top:118px;width:850px"><div style="' . $lblObjC . '">OBJETIVO</div>'
                .   '<div style="font-family:\'Roboto Condensed\';font-weight:400;font-size:16px;line-height:1.4;color:#33335a">' . $objTxt . '</div></div>'
                . '<div style="position:absolute;left:960px;top:120px;width:240px"><div style="' . $lblC . '">TIPO DE ESCOPO</div>'
                .   '<div style="font-family:\'Roboto Condensed\';font-weight:700;font-size:20px;color:#10AAA5">' . e($escTipo) . '</div></div>'
                . '<div data-escopo-box style="position:absolute;left:90px;top:236px;width:1100px;height:426px;overflow:hidden;'
                .   'font-family:\'Roboto Condensed\';font-weight:300;font-size:16px;line-height:1.4;color:#4a4a66">' . $escFunc . $tabelasHtml . '</div>'
                . '<div class="ec-foot"></div></div>';
            // pula TODAS as páginas de escopo/tabela do deck (idx 3-7 = s4..s8) — viram o conteúdo HTML acima + paginasOff do usuário.
            $off = array_values(array_unique(array_merge([3, 4, 5, 6, 7], array_map('intval', (array) data_get($conteudo, 'paginas_off', [])))));

            // ACEITE — MESMA página HTML branded dos demais tipos (slide-12 = idx 11 do deck cloud), com o
            // texto opcional no meio (conteudo.aceite.texto_extra) refluindo igual aos outros contratos.
            $aceiteExtra = trim((string) data_get($conteudo, 'aceite.texto_extra'));
            $cloudAceite = $this->aceitePageHtml($tipo, $contratada, $aceiteExtra);

            return ['codigo' => $p->codigo, 'slides' => $slides, 'overlays' => $overlays,
                    'escopoIndex' => 2, 'escopoPage' => $cloudEscopo, 'aceiteIndex' => 11, 'aceitePage' => $cloudAceite,
                    'paginasOff' => $off, 'manifesto' => $this->assinaturaManifesto($p)];
        }

        // ===== tabelas de INVESTIMENTO =====
        // DESCONTO configurável (rótulo editável, ex.: "Cortesia"; abate do TOTAL) — vale p/ todos os tipos.
        $descVal   = (float) data_get($conteudo, 'investimento.desconto.valor', 0);
        $descLabel = trim((string) data_get($conteudo, 'investimento.desconto.label', '')) ?: 'Desconto';
        // Linha do desconto na área livre à esquerda do "TOTAL" (na faixa do total), quando houver desconto.
        $descLinha = fn ($y) => $descVal > 0
            ? $cel(355, $y, 480, 'left', e($descLabel) . ': &minus; ' . $brl($descVal), 'font-size:13px;font-weight:700;color:#c0392b')
            : '';
        $tabelaHoras = fn () => $mask(351, 223, 856, 55, '#cfd5e9')
            . $cel(335, 241, 265, 'center', (string) $horas, 'font-size:16px;color:#3a3a3a')
            . $cel(600, 241, 268, 'center', $brl($vhCli), 'font-size:16px;color:#3a3a3a')
            . $cel(868, 241, 338, 'center', $brl($total), 'font-size:16px;color:#3a3a3a')
            . $mask(866, 279, 342, 40, '#5eb4aa')
            . $cel(866, 290, 340, 'center', $brl($total - $descVal), 'font-size:18px;font-weight:800;color:#fff')
            . $descLinha(289);
        $tabelaValor = fn ($v) => $mask(636, 248, 572, 54, '#cfd5e9')
            . $cel(600, 264, 268, 'center', $brl($v), 'font-size:16px;color:#3a3a3a')
            . $cel(868, 264, 338, 'center', $brl($v), 'font-size:16px;color:#3a3a3a')
            . $mask(866, 304, 342, 40, '#5eb4aa')
            . $cel(866, 315, 340, 'center', $brl($v - $descVal), 'font-size:18px;font-weight:800;color:#fff')
            . $descLinha(314);
        $escopoObjetivo = fn ($txt) => $mask(274, 206, 948, 28, '#cfd5e9')
            . $frase(283, 210, 940, $txt, 13.5, '#33335a');

        // bloco centralizado (card índigo)
        $blocoC = fn ($l, $t, $w, $txt, $sz, $col, $lh = 1.25) => '<div style="position:absolute;left:' . $l . 'px;top:' . $t . 'px;width:' . $w . 'px;text-align:center;font-family:\'Roboto Condensed\';font-weight:300;font-size:' . $sz . 'px;line-height:' . $lh . ';color:' . $col . '">' . $txt . '</div>';

        // ===== ESCOPO (idx3) — TIPO DE ESCOPO (header índigo) + ESCOPO FUNCIONAL (painel lavanda) =====
        $tipoEscopoOverlay = $over('escopo.tipo_escopo', fn ($v) => $mask(263, 150, 210, 32, '#442B7E')
            . $frase(268, 156, 200, '<b style="font-weight:700">' . e($v) . '</b>', 14, '#ffffff', 700));
        // ===== ESCOPO — página própria (HTML branded, cabeçalho no topo igual à continuação).
        // Substitui a arte do deck (slide idx3): dois blocos no topo (OBJETIVO + TIPO DE ESCOPO)
        // e o ESCOPO FUNCIONAL fluindo em largura cheia (data-escopo-box), paginando p/ continuação.
        $escopoBlocks = $this->escopoBlocksHtml($conteudo, $def, $assetMode, $vars);
        $escopoFuncionalOverlay = ''; // box migrou p/ a página HTML (escopoPage); mantido p/ o switch.
        $escopoTipoVal = data_get($conteudo, 'escopo.tipo_escopo') ?: (data_get($def, 'escopo.tipo_escopo') ?: 'ABERTO');
        // OBJETIVO: texto editável (conteudo.escopo.objetivo). Se vazio, gera automático (horas + escopo_texto).
        $objetivoOverride = trim((string) data_get($conteudo, 'escopo.objetivo'));
        $objetivoTxt = $objetivoOverride !== '' ? nl2br(e($fillVars($objetivoOverride))) : match ($tipo) {
            'bh_mensal'       => 'Aquisição de pacote de consultoria de <b style="font-weight:700">' . $horas . ' horas mensais</b> para ' . e($escopoTexto) . '. Será disponibilizado <b style="font-weight:700">' . $horas . ' horas</b> mensais para utilização dos itens abaixo, com todas as especialidades multidisciplinares da ERPServ.',
            'on_demand'       => 'Consultoria especializada sob demanda em ' . e($escopoTexto) . '.',
            'projeto_fechado' => e($escopoTexto) . '.',
            default           => 'Aquisição de pacote de consultoria de <b style="font-weight:700">' . $horas . ' horas</b> para ' . e($escopoTexto) . '.',
        };
        $lbl = 'font-size:11px;font-weight:700;letter-spacing:1px;color:#442B7E;margin-bottom:3px';
        // OBJETIVO em destaque: selo índigo + texto maior.
        $lblObj = 'display:inline-block;background:#442B7E;color:#fff;font-size:14px;font-weight:700;letter-spacing:1.5px;padding:4px 14px;border-radius:5px;margin-bottom:8px';
        $escopoPageHtml = '<div class="slide escopo-cont">'
            . '<div class="ec-head"><div class="ec-title">ESCOPO</div></div><div class="ec-rule"></div>'
            . '<div style="position:absolute;left:90px;top:118px;width:850px">'
            .   '<div style="' . $lblObj . '">OBJETIVO</div>'
            .   '<div style="font-family:\'Roboto Condensed\';font-weight:400;font-size:16px;line-height:1.4;color:#33335a">' . $objetivoTxt . '</div>'
            . '</div>'
            . '<div style="position:absolute;left:960px;top:120px;width:240px">'
            .   '<div style="' . $lbl . '">TIPO DE ESCOPO</div>'
            .   '<div style="font-family:\'Roboto Condensed\';font-weight:700;font-size:20px;color:#10AAA5">' . e($escopoTipoVal) . '</div>'
            . '</div>'
            . '<div data-escopo-box style="position:absolute;left:90px;top:236px;width:1100px;height:426px;overflow:hidden;'
            .   'font-family:\'Roboto Condensed\';font-weight:300;font-size:16px;line-height:1.4;color:#4a4a66">' . $escopoBlocks . '</div>'
            . '<div class="ec-foot"></div></div>';

        // ===== CARD lateral (BANCO DE HORAS / SOB DEMAND / PROJETOS) — MESMO card nas páginas
        // de Investimento e Prazo. Campo ÚNICO conteudo.card_texto; cor índigo (ou teal no on_demand).
        $cardColor = $tipo === 'on_demand' ? $T : $BG;
        $cardOverlay = $over('card_texto', fn ($v) => $mask(50, 486, 252, 64, $cardColor)
            . $blocoC(50, 492, 252, $nl2br($fillVars($v)), 17, '#ffffff', 1.2));

        // ===== INVESTIMENTO (overrides de texto) — sob demanda (com liga/desliga) + despesas (corpo cinza) =====
        // Desabilitado: cobre cabeçalho "HORAS SOB DEMANDA:" + corpo com a cor do fundo (some da proposta).
        $sobOn = data_get($conteudo, 'investimento.sob_demanda_on') !== false; // default ligado
        // Coordenadas do CORPO das despesas (y do texto, logo abaixo do título baked) POR TIPO — o layout do
        // slide difere entre tipos; coords fixas desalinhavam a "fora" (caía sobre o título) e deixavam sobra baked.
        $dy = [
            'bh_mensal' => ['sp' => 431, 'fora' => 529],
            'bh_fixo'   => ['sp' => 489, 'fora' => 585],
            'on_demand' => ['sp' => 422, 'fora' => 521],
            'projeto_fechado' => ['sp' => 422, 'fora' => 521], // layout = on_demand (preço fixo, sem bloco sob demanda)
        ][$tipo] ?? ['sp' => 431, 'fora' => 529];
        $spY = $dy['sp']; $foraY = $dy['fora'];
        // Bloco "HORAS SOB DEMANDA" só existe no slide do bh_fixo (corpo ≈y399); nos demais o y default não casa
        // (mas só renderiza se o usuário sobrescrever — overlay-on-override).
        $sobY = ['bh_fixo' => 399][$tipo] ?? 357;
        $invTexto = ($sobOn
                ? $over('investimento.sob_demanda', fn ($v) => $mask(350, $sobY - 6, 904, 54, '#f1f1f1')
                    . $bloco(355, $sobY, 892, $nl2br($v), 13.5, '#595959', 1.3))
                : $mask(336, $sobY - 38, 920, 110, '#efefef'))
            . ((data_get($conteudo, 'investimento.despesas_sp_on') !== false)
                ? $over('investimento.despesas_sp', fn ($v) => $mask(350, $spY - 5, 904, 52, '#f1f1f1')
                    . $bloco(355, $spY, 892, $nl2br($v), 13.5, '#595959', 1.3))
                : $mask(336, $spY - 32, 920, 88, '#efefef'))
            . ((data_get($conteudo, 'investimento.despesas_fora_on') !== false)
                ? $over('investimento.despesas_fora', fn ($v) => $mask(350, $foraY - 5, 904, 58, '#f1f1f1')
                    . $bloco(355, $foraY, 892, $nl2br($v), 13.5, '#595959', 1.3))
                : $mask(336, $foraY - 32, 920, 92, '#efefef'));

        // ===== PRAZO (overrides de texto) — início (corpo cinza) + pagamento (o card vem de $cardOverlay) =====
        // Início do atendimento: o corpo baked é left-aligned (x≈210, sob o ícone); só o Y muda por tipo
        // (on_demand fica mais alto). A máscara estreita anterior (w545) deixava sobra baked à direita.
        $iy = [
            'bh_mensal' => 253,
            'bh_fixo'   => 253,
            'on_demand' => 226,
        ][$tipo] ?? 253;
        // Início: corpo alinhado SOB o título (x367, coluna esq.), máscara que NÃO invade a coluna DURAÇÃO
        // (overlay em x793 no bh_fixo) — antes a máscara larga (x814) clipava o "O s" de "O serviço...".
        $prazoTexto = $over('prazo.inicio_atendimento', fn ($v) => $mask(357, $iy - 6, 425, 64, '#f1f1f1')
                . $bloco(367, $iy, 392, $nl2br($v), 13.5, '#595959', 1.3))
            . $over('prazo.pagamento_despesas', fn ($v) => $mask(208, 505, 905, 50, '#f1f1f1')
                . $bloco(213, 509, 895, $nl2br($v), 13.5, '#595959', 1.3));
        // parcelas (BH Fixo / Projeto Fechado): linha lavanda y381-438; colunas centradas em x497/759/1048.
        $parcelasTexto = $over('prazo.parcelas', fn ($v) => $mask(357, 392, 280, 42, '#cfd5e9')
                . $cel(357, 401, 280, 'center', e($v), 'font-size:15px;color:#595959'))
            . $over('prazo.valor_pct', fn ($v) => $mask(638, 392, 242, 42, '#cfd5e9')
                . $cel(638, 401, 242, 'center', '<b style="font-weight:700">' . e($v) . '</b>', 'font-size:15px;color:#3a3a3a'))
            . $over('prazo.vencimento', fn ($v) => $mask(868, 392, 366, 42, '#cfd5e9')
                . $cel(868, 401, 366, 'center', e($v), 'font-size:14px;color:#595959'));

        // ===== ACEITE (índice = slideCount-2 → 7 nos de 10 / 7 no PF de 9... PF aceite=idx7) =====
        // Dados da Contratada (config global) — overlay-on-override vs o texto do deck.
        $aceiteIdx = $tipo === 'projeto_fechado' ? 7 : 8;

        switch ($tipo) {
            case 'bh_mensal':
                $overlays[3] = $escopoObjetivo('Aquisição de pacote de consultoria de <b style="font-weight:700">' . $horas . ' horas mensais</b> para ' . e($escopoTexto) . '.')
                    . $mask(262, 569, 988, 60, '#e8ebf4')
                    . $bloco(268, 574, 976, 'Será disponibilizado <b style="font-weight:700">' . $horas . ' horas</b> mensais para utilização dos itens acima, com todas as especialidades multidisciplinares da ERPServ.', 18, '#4a4a66', 1.32)
                    . $tipoEscopoOverlay;
                $overlays[6] = $tabelaHoras() . $invTexto . $cardOverlay;
                // PRAZO: VALOR da parcela mensal (col meio x643-930) + textos override.
                $overlays[7] = $mask(645, 382, 284, 55, '#cfd5e9')
                    . $cel(645, 400, 284, 'center', $brl($total), 'font-size:15px;font-weight:700;color:#3a3a3a')
                    . $prazoTexto . $cardOverlay;
                break;

            case 'on_demand':
                $overlays[3] = $escopoFuncionalOverlay . $tipoEscopoOverlay;
                $overlays[6] = $tabelaValor($vhCli) . $invTexto . $cardOverlay;
                $overlays[7] = $prazoTexto . $cardOverlay;
                break;

            case 'projeto_fechado':
                $overlays[3] = $escopoObjetivo(e($escopoTexto) . '.') . $escopoFuncionalOverlay . $tipoEscopoOverlay;
                $overlays[5] = $tabelaValor($valorProjeto) . $invTexto . $cardOverlay;
                $overlays[6] = $prazoTexto . $parcelasTexto . $cardOverlay;
                break;

            case 'bh_fixo':
            default:
                $overlays[3] = $escopoObjetivo('Aquisição de pacote de consultoria de <b style="font-weight:700">' . $horas . ' horas</b> para ' . e($escopoTexto) . '.')
                    . $escopoFuncionalOverlay . $tipoEscopoOverlay;
                $overlays[6] = $tabelaHoras() . $invTexto . $cardOverlay;
                // PRAZO: DURAÇÃO DO SERVIÇO em meses (col direita x≈685,y224) + textos override + parcelas.
                $overlays[7] = $mask(793, 252, 362, 26, '#f1f1f1')
                    . $frase(796, 256, 356, 'O serviço tem duração prevista de <b style="font-weight:700">' . $dur . ' meses</b>.', 13.5, '#595959')
                    . $prazoTexto . $parcelasTexto . $cardOverlay;
                break;
        }

        // ===== INVESTIMENTO e PRAZO — extras (títulos/textos posicionáveis) na área livre do rodapé;
        // se não couber, o paginador cria página de continuação branded. =====
        $investIdx = $tipo === 'projeto_fechado' ? 5 : 6;
        $prazoIdx  = $tipo === 'projeto_fechado' ? 6 : 7;
        $investExtras = $this->extrasHtml((array) data_get($conteudo, 'investimento.extras', []));
        $prazoExtras  = $this->extrasHtml((array) data_get($conteudo, 'prazo.extras', []));
        $flowBox = fn (string $attr, int $top, int $h, string $inner) => $inner === '' ? '' :
            '<div ' . $attr . ' style="position:absolute;left:80px;top:' . $top . 'px;width:1150px;height:' . $h . 'px;overflow:hidden;'
            . 'font-family:\'Roboto Condensed\';font-weight:300;font-size:13.5px;line-height:1.3;color:#595959">' . $inner . '</div>';
        if ($investExtras !== '') $overlays[$investIdx] = ($overlays[$investIdx] ?? '') . $flowBox('data-invest-box', 626, 68, $investExtras);
        if ($prazoExtras !== '')  $overlays[$prazoIdx]  = ($overlays[$prazoIdx] ?? '')  . $flowBox('data-prazo-box', 583, 104, $prazoExtras);

        // ===== ACEITE — página HTML que REFLUI (igual ao escopo, no lugar da arte do deck): o texto
        // opcional entra no CORPO empurrando o Foro e os blocos de assinatura p/ baixo; overflow → continuação.
        $aceiteExtra = trim((string) data_get($conteudo, 'aceite.texto_extra'));
        $aceitePage  = $this->aceitePageHtml($tipo, $contratada, $aceiteExtra);

        // Páginas desativadas (conteudo.paginas_off = índices a NÃO renderizar) — o consultor pode optar por não enviar alguma.
        $paginasOff = array_values(array_unique(array_map('intval', (array) data_get($conteudo, 'paginas_off', []))));

        // escopoIndex/aceiteIndex = páginas substituídas por HTML branded (no lugar da arte do deck).
        return ['codigo' => $p->codigo, 'slides' => $slides, 'overlays' => $overlays,
                'escopoIndex' => 3, 'escopoPage' => $escopoPageHtml,
                'aceiteIndex' => $aceiteIdx, 'aceitePage' => $aceitePage,
                'paginasOff' => $paginasOff, 'manifesto' => $this->assinaturaManifesto($p)];
    }

    /**
     * MODO FOCO (preview): dado o módulo aberto no editor, retorna a PÁGINA a focar e os RETÂNGULOS (por campo)
     * que destacam onde cada informação aparece. Reusa as MESMAS coordenadas das máscaras já calibradas.
     * Retorna ['page' => int|null, 'rects' => [campoKey => [l, t, w, h]]].
     */
    public function focusHighlights(string $tipo, string $section): array
    {
        $isPF = $tipo === 'projeto_fechado';
        $isCloud = $tipo === 'cloud';
        $capa = 0;
        $escopo = $isCloud ? 2 : 3;
        $invest = $isCloud ? 9 : ($isPF ? 5 : 6);
        $prazo = $isPF ? 6 : 7;
        $aceite = $isCloud ? 11 : ($isPF ? 7 : 8);
        // coords por tipo (espelham buildRenderData)
        $dy = ['bh_mensal' => ['sp' => 431, 'fora' => 529], 'bh_fixo' => ['sp' => 489, 'fora' => 585], 'on_demand' => ['sp' => 422, 'fora' => 521], 'projeto_fechado' => ['sp' => 422, 'fora' => 521]][$tipo] ?? ['sp' => 431, 'fora' => 529];
        $iy = ['bh_mensal' => 253, 'bh_fixo' => 253, 'on_demand' => 226, 'projeto_fechado' => 226][$tipo] ?? 253;
        $sobY = ['bh_fixo' => 399][$tipo] ?? 357;

        switch ($section) {
            case 'ident':
                $r = ['dados' => [178, 426, 566, 130]];
                if ($isCloud) $r['capa_nome'] = [172, 340, 520, 42];
                return ['page' => $capa, 'rects' => $r];
            case 'capa':
                return ['page' => $capa, 'rects' => ['logo' => [974, 400, 280, 152]]];
            case 'escopo':
                return ['page' => $escopo, 'rects' => ['escopo' => [80, 110, 1120, 566]]];
            case 'cloudsla':
                // tabelas (Horário/SLA/Estrutura) FLUEM e paginam → destaque via CSS [data-hl] (não coord fixa).
                return ['page' => $escopo, 'rects' => []];
            case 'calc':
            case 'invest':
                if ($isCloud) return ['page' => $invest, 'rects' => ['tabela' => [342, 172, 882, 226]]];
                $r = ['tabela' => [348, 178, 904, 165]];
                if ($tipo === 'bh_fixo') $r['sob_demanda'] = [350, $sobY - 6, 904, 54]; // só o bh_fixo tem esse bloco
                $r['despesas_sp'] = [350, $dy['sp'] - 5, 904, 52];
                $r['despesas_fora'] = [350, $dy['fora'] - 5, 904, 58];
                return ['page' => $invest, 'rects' => $r];
            case 'cloudinv':
                return ['page' => $invest, 'rects' => ['tabela' => [342, 172, 882, 226]]];
            case 'cloudinvmensal':
                // Página do Investimento MENSAL (separada do Único) — overlay em idx 10 (ver buildRenderData).
                return ['page' => $isCloud ? 10 : $invest, 'rects' => ['tabela' => [342, 172, 882, 226]]];
            case 'prazo':
                $r = ['inicio' => [357, $iy - 6, 425, 64]];
                if ($tipo === 'bh_fixo') {
                    $r['duracao'] = [793, 248, 362, 32];           // "DURAÇÃO DO SERVIÇO" (coluna direita)
                    $r['pagamento'] = [360, 518, 800, 58];         // corpo do "PAGAMENTO DAS DESPESAS" (medido)
                } elseif ($tipo === 'bh_mensal') {
                    $r['duracao'] = [932, 250, 300, 34];           // "O serviço tem duração indeterminada." (coluna direita)
                    $r['pagamento'] = [360, 518, 800, 58];         // mesmo layout do bh_fixo (medido)
                } else {
                    $r['pagamento'] = [208, 505, 905, 50];
                }
                if ($tipo === 'bh_fixo' || $isPF) $r['parcelas'] = [357, 388, 877, 50];
                return ['page' => $prazo, 'rects' => $r];
            case 'aceite':
                return ['page' => $aceite, 'rects' => ['aceite' => [80, 110, 1120, 566]]];
        }
        return ['page' => null, 'rects' => []];
    }

    /**
     * Página do ACEITE como HTML branded (no lugar da arte do deck), p/ o conteúdo REFLUIR:
     * o texto opcional (conteudo.aceite.texto_extra) entra no corpo entre os termos e o Foro,
     * empurrando o Foro + blocos de assinatura p/ baixo; o paginador do blade cria continuação no overflow.
     * Os parágrafos do meio são específicos por tipo de contrato (espelham a arte original).
     */
    private function aceitePageHtml(string $tipo, array $contratada, string $aceiteExtra): string
    {
        $link = 'https://erpserv.com.br/wp-content/uploads/2022/09/CERTIFICADO-DE-REGISTRO.pdf';
        $p1   = 'O contrato ora firmado é composto pelo documento descrito, estando todos disponíveis no endereço <a href="' . $link . '">' . $link . '</a>, cujos termos o CLIENTE, neste ato, declara o conhecimento e concordância.';
        $p2   = 'Contrato de prestação de serviços devidamente registrado perante o 8º Oficial de Registro de Títulos e Documentos e Civil de Pessoa Jurídica da Comarca de São Paulo sob o n° 1.546.416.';
        $foro = 'As partes elegem o Foro Central de São Paulo – Capital, para a solução de quaisquer pendências oriundas do presente contrato, por mais privilegiado que qualquer outro possa ser.';

        // Parágrafos do meio (entre os termos e o Foro), fiéis à arte de cada tipo.
        $meio = match ($tipo) {
            'bh_fixo' => [
                'As horas são expectativas de acordo com alinhamento entre as partes, podendo ser flexibilizadas para menor ou maior.',
                'A capacidade mensal de horas é definida pela divisão do Banco de Horas total pelo número de meses do contrato.',
            ],
            'projeto_fechado' => [
                'Caso a contratante queira utilizar demais serviços da contratada ou executar desvios de escopos, será cobrado R$ 190,00/hora dentro do horário comercial, com fechamentos mensais e pagamento até dia 10 do mês subsequente.',
            ],
            default => [], // bh_mensal e on_demand não têm parágrafo do meio
        };
        // Destaque índigo após o Foro (somente on_demand).
        $hl = $tipo === 'on_demand' ? 'Contrato sem fidelização e horas sob demanda.' : '';

        $body  = '<div class="ac-sec">• TERMOS DE ACEITAÇÃO DA PROPOSTA COMERCIAL:</div>';
        $body .= '<div class="ac-p">' . $p1 . '</div>';
        $body .= '<div class="ac-p">' . e($p2) . '</div>';
        foreach ($meio as $m) $body .= '<div class="ac-p">' . e($m) . '</div>';
        // TEXTO OPCIONAL — entra aqui (no espaço entre os termos e o Foro), empurrando o resto p/ baixo.
        foreach (preg_split('/\r?\n/', $aceiteExtra) as $ln) {
            $ln = trim($ln);
            if ($ln !== '') $body .= '<div class="ac-p">' . e($ln) . '</div>';
        }
        $body .= '<div class="ac-p">' . e($foro) . '</div>';
        if ($hl !== '') $body .= '<div class="ac-hl">' . e($hl) . '</div>';

        // Blocos de assinatura: cada título + tabela agrupados (.ac-grp = 1 bloco do paginador → nunca órfão;
        // fluem junto, empurrados p/ baixo e movem inteiros p/ a continuação se faltar espaço).
        $body .= '<div class="ac-grp"><div class="ac-sec ac-mt">• ACEITE DA CONTRANTE</div>'
            . '<div class="ac-tbl"><div class="ac-th">REPRESENTANTE LEGAL</div>'
            .   '<div class="ac-bd ac-sign">'
            .     '<div class="ac-row"><span class="c1"><b>NOME COMPLETO:</b></span><span class="c2"><b>ASSINATURA:</b></span></div>'
            .     '<div class="ac-row"><span class="c1"><b>DATA:</b></span></div>'
            .   '</div></div></div>';
        $body .= '<div class="ac-grp"><div class="ac-sec ac-mt">• DADOS DA CONTRATADA</div>'
            . '<div class="ac-tbl"><div class="ac-th">REPRESENTANTE LEGAL</div>'
            .   '<div class="ac-bd">'
            .     '<div class="ac-row"><span class="c1"><b>NOME:</b> ' . e($contratada['nome'] ?? '') . '</span><span class="c2"><b>CNPJ:</b> ' . e($contratada['cnpj'] ?? '') . '</span></div>'
            .     '<div class="ac-row"><span class="c1"><b>ENDEREÇO:</b> ' . e($contratada['endereco'] ?? '') . '</span><span class="c2"><b>CEP:</b> ' . e($contratada['cep'] ?? '') . '</span></div>'
            .   '</div></div></div>';

        return '<div class="slide aceite-cont">'
            . '<div class="ec-head"><div class="ec-title">ACEITE</div></div><div class="ec-rule"></div>'
            . '<div data-aceite-box>' . $body . '</div>'
            . '<div class="ec-foot"></div></div>';
    }

    /**
     * Renderiza uma lista de EXTRAS (títulos/textos posicionáveis) como blocos .eb-line que fluem e paginam.
     * Cada item: ['tipo' => 'titulo'|'texto', 'texto' => string, 'align' => 'left'|'center'|'right'].
     * Título = negrito, roxo (#442B7E), fonte condensada do deck; Texto = parágrafo cinza. $tSize = px do título.
     */
    private function extrasHtml(array $extras, float $tSize = 20): string
    {
        $h = '';
        foreach ($extras as $it) {
            if (!is_array($it)) continue;
            $texto = trim((string) ($it['texto'] ?? ''));
            if ($texto === '') continue;
            $align = in_array($it['align'] ?? '', ['left', 'center', 'right'], true) ? $it['align'] : 'left';
            if (($it['tipo'] ?? 'texto') === 'titulo') {
                $h .= '<div class="eb-line" style="text-align:' . $align . ';font-family:\'Roboto Condensed\',Arial,sans-serif;'
                    . 'font-weight:700;font-size:' . $tSize . 'px;letter-spacing:.4px;color:#442B7E;margin:6px 0 8px">' . e($texto) . '</div>';
            } else {
                foreach (preg_split('/\r?\n/', $texto) as $ln) {
                    $ln = trim($ln);
                    if ($ln !== '') $h .= '<div class="eb-line" style="text-align:' . $align . ';margin:0 0 7px">' . e($ln) . '</div>';
                }
            }
        }
        return $h;
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
        // Manifesto de assinaturas: a proposta do preview é transiente (sem participantes). Se o id real veio,
        // puxa as assinaturas da proposta persistida p/ a página de registro aparecer no preview do editor.
        if (!empty($spec['proposal_id']) && ($real = CrmProposal::find($spec['proposal_id']))) {
            $data['manifesto'] = $this->assinaturaManifesto($real);
        }
        // MODO FOCO (só no preview): módulo aberto no editor → mostra só a página dele + retângulos coloridos
        // por campo (cada cor = um campo, casando com a legenda do editor). PDF final nunca recebe isto.
        $focus = trim((string) ($spec['focus'] ?? ''));
        $colors = (array) ($spec['highlights'] ?? []);
        if ($focus !== '') {
            $fh = $this->focusHighlights($tipo, $focus);
            if ($fh['page'] !== null) {
                $fp = (int) $fh['page'];
                $total = count($data['slides']);
                $off = $data['paginasOff'] ?? [];
                for ($i = 0; $i < $total; $i++) if ($i !== $fp) $off[] = $i;
                $data['paginasOff'] = array_values(array_unique($off));
                $hl = '';
                foreach ($fh['rects'] as $key => $r) {
                    if (!isset($colors[$key])) continue; // só destaca os campos que o editor pediu (com cor)
                    $c = (string) $colors[$key];
                    $hl .= '<div style="position:absolute;left:' . $r[0] . 'px;top:' . $r[1] . 'px;width:' . $r[2] . 'px;height:' . $r[3] . 'px;'
                        . 'border:3px solid ' . $c . ';background:' . $c . '26;border-radius:6px;box-shadow:0 0 0 2px ' . $c . '55;pointer-events:none;z-index:60"></div>';
                }
                $data['overlays'][$fp] = ($data['overlays'][$fp] ?? '') . $hl;
                // Escopo Cloud: tabelas/objetivo fluem e paginam → destaque por CSS nos blocos data-hl (vale em todas
                // as páginas de continuação). Injetado no escopoPage (a página de escopo é HTML, não usa overlay).
                if ($focus === 'cloudsla' && !empty($data['escopoPage'])) {
                    $css = '';
                    foreach (['escopo', 'horario', 'sla', 'estrutura'] as $k) {
                        if (isset($colors[$k])) {
                            $c = (string) $colors[$k];
                            $css .= '[data-hl="' . $k . '"]{outline:3px solid ' . $c . ';outline-offset:3px;border-radius:6px;box-shadow:0 0 0 2px ' . $c . '55}';
                        }
                    }
                    if ($css !== '') $data['escopoPage'] .= '<style>' . $css . '</style>';
                }
            }
        }
        // HTML completo (mesmo blade do PDF) p/ o preview rodar o paginador do escopo no iframe.
        $html = view('pdf.documents.proposta.render', [
            'slides' => $data['slides'], 'overlays' => $data['overlays'], 'codigo' => $p->codigo,
            'escopoIndex' => $data['escopoIndex'] ?? null, 'escopoPage' => $data['escopoPage'] ?? null,
            'aceiteIndex' => $data['aceiteIndex'] ?? null, 'aceitePage' => $data['aceitePage'] ?? null,
            'paginasOff' => $data['paginasOff'] ?? [],
            'manifesto' => $data['manifesto'] ?? [],
        ])->render();
        return [
            'codigo'   => $p->codigo,
            'calc'     => $calcOut,
            'slides'   => $data['slides'],
            'overlays' => $data['overlays'],
            'html'     => $html,
        ];
    }

    /** Textos-padrão do deck por tipo (base do overlay-on-override e do pré-preenchimento do editor). */
    public function proposalDefaults(string $tipo): array
    {
        $aberto = $tipo === 'projeto_fechado' ? 'FECHADO' : 'ABERTO';
        // Texto-padrão do card lateral por tipo (igual à arte do deck) — campo ÚNICO p/ as duas páginas.
        $cardDefaults = [
            'bh_fixo'         => 'Banco de horas fixo para utilização em até 01 ano',
            'bh_mensal'       => 'Banco de horas mensal recorrente ou pacote de horas fixo.',
            'on_demand'       => 'Conforme a quantidade de horas trabalhadas no mês e taxa/hora predefinida.',
            'projeto_fechado' => 'Escopo fechado, prazo definido e valor fixo.',
        ];
        return [
            'card_texto' => $cardDefaults[$tipo] ?? $cardDefaults['bh_fixo'],
            'escopo' => [
                'tipo_escopo'      => $aberto,
                // Objetivo PRÉ-PREENCHIDO com tags ({horas}, {servico}…) — o usuário edita; o render troca pelos valores.
                'objetivo'         => match ($tipo) {
                    'bh_mensal'       => 'Aquisição de pacote de consultoria de {horas} horas mensais para {servico}. Será disponibilizado {horas} horas mensais para utilização dos itens abaixo, com todas as especialidades multidisciplinares da ERPServ.',
                    'on_demand'       => 'Consultoria especializada sob demanda em {servico}.',
                    'projeto_fechado' => '{servico}.',
                    'cloud'           => 'Contratação de serviço de disponibilidade em cloud do ERP PROTHEUS 12.',
                    default           => 'Aquisição de pacote de consultoria de {horas} horas para {servico}.',
                },
                'escopo_funcional' => "Nosso objetivo principal é a abertura de um canal para atendimentos, nos moldes de banco de horas fixo, dentro do TOTVS Protheus, Fluig e Power BI, de acordo com as necessidades e alinhamento prévio com a contratante.\n\nAs principais atividades executadas serão:\n• Diagnóstico de ambiente\n• Consultoria de processos\n• Sustentação\n• Manutenção\n• Desenvolvimentos\n• Gerenciamento de Projetos",
            ],
            'investimento' => [
                'sob_demanda'   => 'Caso a contratante queira utilizar demais serviços da contratada ou ultrapassar as horas contratadas, será cobrado R$ 190,00/hora dentro do horário comercial, com fechamentos mensais e pagamento até dia 10 do mês subsequente.',
                'despesas_sp'   => 'Será cobrado R$170,00 por visita/consultor, para suprir as despesas com alimentação, estacionamento, traslado, combustível, pedágios.',
                'despesas_fora' => 'Será cobrado R$250 por visita/consultor, para suprir as despesas com alimentação, estacionamento, traslado, combustível, pedágios. Despesas como passagem aérea/km e hospedagem deverão ser custeadas pela contratante.',
            ],
            'prazo' => [
                'inicio_atendimento' => 'O atendimento será iniciado em até 07 dias úteis após a data de assinatura da proposta.',
                'pagamento_despesas' => 'Todas as despesas reembolsáveis serão cobradas via nota de débito no dia 10 do mês posterior ao mês de prestação dos serviços.',
                'parcelas'           => '2x',
                'valor_pct'          => '50% em cada parcela',
                'vencimento'         => '10 / 40 Dias após assinatura da proposta',
            ],
            'aceite' => [
                'texto_extra' => '',
                'contratada'  => $this->contratadaPadrao(),
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
    /** Resolve uma imagem de bloco do Escopo (Attachment) p/ data-URI (PDF) ou URL (preview). */
    private function resolveEscopoImage($attId, string $assetMode): ?string
    {
        if (!$attId) return null;
        $att = \App\Models\Attachment::find($attId);
        if (!$att || $att->category !== 'escopo') return null;
        if ($assetMode === 'url') {
            return '/api/v1/crm/proposals/escopo-image/' . $att->id;
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
     * Monta o HTML interno (blocos) do Escopo funcional p/ fluir/paginar.
     * Blocos: {tipo:'texto',conteudo} | {tipo:'imagem',attachment_id,largura,alinhamento,legenda}.
     * Retrocompat: sem blocks, usa o texto de escopo_funcional (override) como bloco único.
     * Retorna '' quando não há conteúdo próprio (deixa a arte/padrão do deck aparecer).
     */
    private function escopoBlocksHtml(array $conteudo, array $def, string $assetMode, array $vars = []): string
    {
        $fill = fn (string $t) => $vars ? preg_replace_callback('/\{([a-zA-Z_]+)\}/', fn ($m) => $vars[strtolower($m[1])] ?? $m[0], $t) : $t;
        $blocks = data_get($conteudo, 'escopo.blocks');
        if (!is_array($blocks) || count($blocks) === 0) {
            // Sem blocos: usa o override de texto OU o texto padrão do deck (a página do escopo
            // agora é HTML — não há mais a arte com o texto embutido p/ servir de fallback).
            $txt = data_get($conteudo, 'escopo.escopo_funcional') ?: data_get($def, 'escopo.escopo_funcional');
            if ($txt === null || trim((string) $txt) === '') return '';
            $blocks = [['tipo' => 'texto', 'conteudo' => $txt]];
        }
        $html = '';
        foreach ($blocks as $b) {
            $tipo = $b['tipo'] ?? 'texto';
            if ($tipo === 'titulo') {
                // TÍTULO: negrito, roxo, fonte condensada do deck — alinhável; flui junto com texto/imagem.
                $txt = trim((string) ($b['conteudo'] ?? ''));
                if ($txt === '') continue;
                $al = in_array($b['alinhamento'] ?? 'left', ['left', 'center', 'right'], true) ? $b['alinhamento'] : 'left';
                $html .= '<div class="eb-line" style="text-align:' . $al . ';font-family:\'Roboto Condensed\',Arial,sans-serif;'
                    . 'font-weight:700;font-size:22px;letter-spacing:.4px;color:#442B7E;margin:6px 0 8px">' . e($fill($txt)) . '</div>';
            } elseif ($tipo === 'imagem') {
                $src = $this->resolveEscopoImage($b['attachment_id'] ?? null, $assetMode);
                if (!$src) continue;
                $w = max(10, min(100, (int) ($b['largura'] ?? 80)));
                $al = in_array($b['alinhamento'] ?? 'center', ['left', 'center', 'right'], true) ? $b['alinhamento'] : 'center';
                $leg = trim((string) ($b['legenda'] ?? ''));
                $html .= '<figure class="eb eb-img" style="text-align:' . $al . '">'
                    . '<img src="' . $src . '" style="width:' . $w . '%;max-width:100%;max-height:560px;object-fit:contain;display:inline-block">'
                    . ($leg !== '' ? '<figcaption style="font-size:12px;color:#7a7a90;margin-top:4px">' . e($leg) . '</figcaption>' : '')
                    . '</figure>';
            } else {
                $txt = rtrim((string) ($b['conteudo'] ?? ''));
                if (trim($txt) === '') continue;
                // Tamanho de fonte (px), marcador (bolinha/check) e negrito inline (**palavra**) por bloco.
                $fs = (int) ($b['font_size'] ?? 0);
                $style = ($fs >= 8 && $fs <= 40) ? ' style="font-size:' . $fs . 'px"' : '';
                $marker = $b['marker'] ?? 'none';
                $pre = $marker === 'bullet' ? '&bull;&nbsp;' : ($marker === 'check' ? '&#10003;&nbsp;' : '');
                // Cada LINHA vira uma unidade de fluxo (assim bullets/parágrafos paginam entre
                // páginas em vez de um bloco único ser cortado). Linha em branco = respiro.
                foreach (preg_split('/\r\n|\r|\n/', $txt) as $line) {
                    if (trim($line) === '') { $html .= '<div class="eb-gap"></div>'; continue; }
                    // tags {chave} preenchidas + negrito **palavra** → <b> (conteúdo escapado por e()).
                    $body = preg_replace('/\*\*(.+?)\*\*/u', '<b style="font-weight:700">$1</b>', e($fill($line)));
                    $html .= '<div class="eb-line"' . $style . '>' . $pre . $body . '</div>';
                }
            }
        }
        return $html;
    }

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
        $ativas = CrmProposal::where('opportunity_id', $opp->id)
            ->whereNotIn('status', ['cancelada', 'reprovada', 'expirada'])
            ->orderByDesc('versao')->orderByDesc('id')->get();
        if ($ativas->isEmpty()) return;
        // O valor da oportunidade adere à proposta: prefere a mais recente COM valor (> 0); só cai p/ a
        // mais recente (0) se NENHUMA proposta ativa tiver valor — evita que uma versão/proposta ainda
        // não precificada zere um valor já existente.
        $escolhida = $ativas->first(fn ($x) => (float) $x->total > 0) ?: $ativas->first();
        $opp->update(['valor' => $escolhida->total]);
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
