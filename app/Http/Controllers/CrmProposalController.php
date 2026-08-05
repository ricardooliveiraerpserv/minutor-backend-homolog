<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentService;
use App\Documents\CrmProposalService;
use App\Models\Attachment;
use App\Models\CrmProposal;
use App\Models\CrmProposalCalc;
use App\Models\CrmProposalShare;
use App\Models\CrmOpportunityEvent;
use App\Models\DocumentEvent;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CRM — Propostas de uma oportunidade. */
class CrmProposalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['opportunity_id' => 'required|exists:crm_opportunities,id']);
        $rows = CrmProposal::with('vendedor:id,name')
            ->where('opportunity_id', $request->opportunity_id)
            ->orderByDesc('numero')->orderByDesc('versao')->get()
            ->map(fn ($p) => array_merge($p->toArray(), ['total' => $p->total]));
        return response()->json(['data' => $rows]);
    }

    private function rules(bool $creating): array
    {
        return [
            'opportunity_id' => ($creating ? 'required' : 'sometimes') . '|exists:crm_opportunities,id',
            'data_emissao'   => 'nullable|date',
            'data_validade'  => 'nullable|date',
            'valor'          => 'nullable|numeric|min:0',
            'descontos'      => 'nullable|numeric|min:0',
            'vendedor_id'    => 'nullable|exists:users,id',
            'memoria_calculo' => 'nullable|array',
            'status'         => 'nullable|in:' . implode(',', CrmProposal::STATUSES),
            'versao'         => 'nullable|integer|min:1',
        ];
    }

    public function store(Request $request, CrmProposalService $svc): JsonResponse
    {
        // Política Comercial — gerar propostas exige permissão do perfil.
        $u = $request->user();
        if ($u && !$u->isAdmin() && !app(\App\Services\PolicyResolver::class)->can($u, 'crm', 'proposals.create')) {
            return response()->json(['message' => 'Seu perfil não permite gerar propostas.'], 403);
        }
        // REGRA: oportunidade com proposta ASSINADA (ou já liberada/convertida) NÃO aceita nova proposta —
        // a negociação está fechada. Para revisar, cancele a assinatura/contrato.
        $oppId = $request->input('opportunity_id');
        if ($oppId && CrmProposal::where('opportunity_id', $oppId)->whereNull('deleted_at')
                ->whereIn('status', ['assinada', 'liberada', 'convertida'])->exists()) {
            return response()->json([
                'message' => 'Esta oportunidade já tem uma proposta assinada — não é possível incluir uma nova.',
                'code'    => 'PROPOSAL_SIGNED_LOCK',
            ], 422);
        }
        // Fluxo NOVO (com tipo + memória de cálculo): delega ao motor único — gera o calc congelado,
        // reserva o código (sequenciador dos projetos), seta status oficial e o tipo do template.
        if ($request->filled('tipo')) {
            $request->validate([
                'opportunity_id'   => 'required|exists:crm_opportunities,id',
                'tipo'             => 'required|in:bh_fixo,bh_mensal,on_demand,projeto_fechado,cloud',
                'modo_faturamento' => 'nullable|in:por_hora,valor_fixo',
                'inputs'           => 'nullable|array',
                'data_validade'    => 'nullable|date',
            ]);
            // Descrição da Oportunidade é obrigatória ANTES de gerar a proposta — EXCETO em
            // renovação/expansão (geradas automaticamente, sem descrição manual): nesses casos a
            // descrição é derivada do título (contexto = renovação/expansão do contrato vinculado).
            $opp = \App\Models\CrmOpportunity::find($request->opportunity_id);
            if ($opp && !filled($opp->descricao)) {
                $derivavel = $opp->renewal_contract_id !== null || in_array($opp->tipo, ['renovacao', 'expansao']);
                if (!$derivavel) {
                    return response()->json(['message' => 'Preencha a "Descrição da Oportunidade" antes de criar a proposta.'], 422);
                }
                $opp->update(['descricao' => $opp->title]); // contexto mínimo p/ a proposta
            }
            // Precificação é da PROPOSTA (não há memória de cálculo na oportunidade). Inputs default.
            $inputs = (array) $request->input('inputs', []);
            if (empty($inputs)) $inputs = ['duracao_meses' => 12];
            $p = $svc->criar([
                'opportunity_id'   => (int) $request->opportunity_id,
                'tipo'             => $request->tipo,
                'modo_faturamento' => $request->input('modo_faturamento') ?: ($request->tipo === 'projeto_fechado' ? 'valor_fixo' : 'por_hora'),
                'inputs'           => $inputs,
                'data_validade'    => $request->input('data_validade'),
            ], $request->user());
            CrmOpportunityEvent::log((int) $request->opportunity_id, 'note', ['to_value' => "Proposta #{$p->numero} ({$p->tipo}) criada"]);
            return response()->json(['data' => array_merge($p->load('vendedor:id,name')->toArray(), ['total' => $p->total])], 201);
        }

        // Fluxo LEGADO (sem tipo): cria a linha direta com valor/descontos informados.
        $v = $request->validate($this->rules(true));
        // Número sequencial por oportunidade; versão default 1.
        $v['numero'] = (int) CrmProposal::where('opportunity_id', $v['opportunity_id'])->max('numero') + 1;
        $v['versao'] = $v['versao'] ?? 1;
        $v['created_by_id'] = auth()->id();
        $p = CrmProposal::create($v);
        CrmOpportunityEvent::log((int) $v['opportunity_id'], 'note', ['to_value' => "Proposta #{$p->numero} criada"]);
        return response()->json(['data' => array_merge($p->load('vendedor:id,name')->toArray(), ['total' => $p->total])], 201);
    }

    public function update(Request $request, CrmProposal $crmProposal, CrmProposalService $svc): JsonResponse
    {
        $crmProposal->update($request->validate($this->rules(false)));
        $svc->syncOppValor($crmProposal); // status/valor mudou → reflete no valor da oportunidade
        return response()->json(['data' => array_merge($crmProposal->fresh()->load('vendedor:id,name')->toArray(), ['total' => $crmProposal->total])]);
    }

    public function destroy(CrmProposal $crmProposal, CrmProposalService $svc): JsonResponse
    {
        $opp = $crmProposal->opportunity;
        $crmProposal->delete();
        if ($opp) { $clone = new CrmProposal(['opportunity_id' => $opp->id]); $clone->setRelation('opportunity', $opp); $svc->syncOppValor($clone); }
        return response()->json(null, 204);
    }

    /** Detalhe completo p/ o editor: proposta + memória de cálculo (inputs/outputs) + conteúdo + defaults + contratada. */
    public function show(CrmProposal $crmProposal, CrmProposalService $svc): JsonResponse
    {
        $crmProposal->load(['calc', 'customer:id,name,cgc,code_prefix', 'vendedor:id,name', 'document:id,status']);
        $tipo = $crmProposal->tipo ?: 'bh_fixo';
        return response()->json(['data' => [
            'id' => $crmProposal->id, 'codigo' => $crmProposal->codigo, 'tipo' => $tipo,
            'opportunity_id' => $crmProposal->opportunity_id,
            'numero' => $crmProposal->numero, 'versao' => $crmProposal->versao, 'status' => $crmProposal->status,
            'data_emissao' => optional($crmProposal->data_emissao)->toDateString(),
            'data_validade' => optional($crmProposal->data_validade)->toDateString(),
            'valor' => (float) $crmProposal->valor, 'descontos' => (float) $crmProposal->descontos, 'total' => $crmProposal->total,
            'document_id' => $crmProposal->document_id,
            'modo_faturamento' => $crmProposal->calc?->modo_faturamento ?? ($tipo === 'projeto_fechado' ? 'valor_fixo' : 'por_hora'),
            'inputs'   => (array) ($crmProposal->calc?->inputs ?? []),
            'calc'     => (array) ($crmProposal->calc?->outputs ?? []),
            'conteudo' => (array) ($crmProposal->conteudo ?? []),
            'defaults' => $svc->proposalDefaults($tipo),
            'contratada' => $svc->contratadaConfig(),
            'customer' => $crmProposal->customer ? ['id' => $crmProposal->customer->id, 'name' => $crmProposal->customer->name, 'cgc' => $crmProposal->customer->cgc] : null,
            'vendedor' => $crmProposal->vendedor ? ['name' => $crmProposal->vendedor->name] : null,
        ]]);
    }

    /** Preview SEM persistir (debounced no editor): slides (URL) + overlays + memória de cálculo recalculada. */
    public function preview(Request $request, CrmProposalService $svc): JsonResponse
    {
        $request->validate([
            'tipo'     => 'required|in:bh_fixo,bh_mensal,on_demand,projeto_fechado,cloud',
            'inputs'   => 'nullable|array',
            'conteudo' => 'nullable|array',
            'modo_faturamento' => 'nullable|in:por_hora,valor_fixo',
            'focus'      => 'nullable|string|max:30',
            'highlights' => 'nullable|array',
            'proposal_id' => 'nullable|integer',
        ]);
        $data = $svc->previewData([
            'proposal_id' => $request->input('proposal_id'),
            'tipo' => $request->tipo,
            'inputs' => $request->input('inputs', []),
            'conteudo' => $request->input('conteudo', []),
            'modo_faturamento' => $request->input('modo_faturamento'),
            'codigo' => $request->input('codigo'),
            'versao' => $request->input('versao', 1),
            'customer_id' => $request->input('customer_id'),
            'vendedor_id' => $request->input('vendedor_id'),
            'data_emissao' => $request->input('data_emissao'),
            'focus' => $request->input('focus'),
            'highlights' => $request->input('highlights', []),
        ]);
        return response()->json(['data' => $data]);
    }

    /** Salva edição in-place (recalcula memória de cálculo; não gera nova versão). */
    public function editar(Request $request, CrmProposal $crmProposal, CrmProposalService $svc): JsonResponse
    {
        // P-E.2.2 — critério de conclusão da assinatura é PARÂMETRO DE PROCESSO, ajustável mesmo em assinatura.
        if ($request->has('assinatura_modo')) {
            $request->validate(['assinatura_modo' => 'in:todos,um_por_parte']);
            $crmProposal->update(['assinatura_modo' => $request->input('assinatura_modo')]);
            app(\App\Services\ProposalParticipantService::class)->recomputarAssinatura($crmProposal->fresh(['participants']));
            if (count(array_diff(array_keys($request->except('_token')), ['assinatura_modo'])) === 0) {
                $crmProposal->refresh();
                return response()->json(['data' => array_merge($crmProposal->toArray(), ['total' => $crmProposal->total])]);
            }
        }
        // P-E.2.1 — bloqueio jurídico: proposta EM ASSINATURA / assinada / liberada / convertida não pode ser editada.
        if (in_array($crmProposal->status, ['aguardando_assinatura', 'assinada', 'liberada', 'convertida'], true)) {
            return response()->json(['message' => 'Proposta em assinatura/assinada não pode ser editada. Cancele a assinatura para gerar uma nova versão.', 'code' => 'LOCKED_FOR_SIGNATURE'], 422);
        }
        $request->validate([
            'inputs'   => 'nullable|array',
            'conteudo' => 'nullable|array',
            'data_emissao'  => 'nullable|date',
            'data_validade' => 'nullable|date',
            'tipo' => 'nullable|in:bh_fixo,bh_mensal,on_demand,projeto_fechado,cloud',
            'modo_faturamento' => 'nullable|in:por_hora,valor_fixo',
            'codigo' => 'nullable|string|max:30',
        ]);
        // Código editável — valida DUPLICAÇÃO: bloqueia se outra proposta (sequência diferente, i.e. numero
        // distinto — versões compartilham numero) já usa o mesmo código.
        $codigo = trim((string) $request->input('codigo', ''));
        if ($codigo !== '' && $codigo !== (string) $crmProposal->codigo) {
            if ($this->codigoDuplicado($crmProposal, $codigo)) {
                return response()->json(['message' => "Código \"{$codigo}\" já está em uso em outra proposta."], 422);
            }
            $crmProposal->codigo = $codigo;
            $crmProposal->save();
        }
        $p = $svc->editar($crmProposal, $request->only(['inputs', 'conteudo', 'data_emissao', 'data_validade', 'tipo', 'modo_faturamento']), $request->user());
        return response()->json(['data' => array_merge($p->toArray(), ['total' => $p->total])]);
    }

    /** Código já em uso? Valida contra o MÓDULO DE SERVIÇO (projects.code — o código vira o code do
     *  projeto/contrato ao converter) E contra outras propostas (sequência diferente; versões compartilham numero). */
    private function codigoDuplicado(CrmProposal $crmProposal, string $codigo): bool
    {
        if (\App\Models\Project::where('code', $codigo)->exists()) {
            return true;
        }
        return CrmProposal::whereNull('deleted_at')
            ->where('id', '!=', $crmProposal->id)
            ->where('codigo', $codigo)
            ->where(fn ($q) => $q->whereNull('numero')->orWhere('numero', '!=', $crmProposal->numero))
            ->exists();
    }

    /** Checagem AO VIVO de disponibilidade do código (usada pelo editor enquanto digita). */
    public function codigoCheck(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        $codigo = trim((string) $request->query('codigo', ''));
        $disponivel = $codigo === '' || $codigo === (string) $crmProposal->codigo || !$this->codigoDuplicado($crmProposal, $codigo);
        return response()->json(['disponivel' => $disponivel]);
    }

    /** Upload do logo do cliente (capa) → Attachment + grava logo_attachment_id no conteudo. */
    public function logo(Request $request, CrmProposal $crmProposal, AttachmentService $attachments): JsonResponse
    {
        $request->validate(['file' => 'required|file|image|max:5120']);
        $att = $attachments->store($request->user(), [
            'entity_type' => 'CRM_PROPOSAL', 'entity_id' => $crmProposal->id,
            'category' => 'logo', 'file' => $request->file('file'),
        ], $request);
        $conteudo = (array) ($crmProposal->conteudo ?? []);
        $conteudo['logo_attachment_id'] = $att->id;
        $crmProposal->update(['conteudo' => $conteudo]);
        return response()->json(['data' => ['logo_attachment_id' => $att->id, 'url' => "/api/v1/crm/proposals/logo/{$att->id}"]]);
    }

    /** Serve o PNG do artwork ou a fonte do deck p/ o preview (mesma origem → sem violar CSP). */
    public function artwork(Request $request)
    {
        $path = (string) $request->query('path', '');
        $isSlide = preg_match('#^slides/(bh_fixo|bh_mensal|on_demand|projeto_fechado|cloud)/slide-\d{2}\.png$#', $path);
        $isFont  = preg_match('#^fonts/(RobotoCondensed|BebasNeue)\.ttf$#', $path);
        abort_unless($isSlide || $isFont, 404);
        $file = resource_path('assets/propostas/' . $path);
        abort_unless(is_file($file), 404);
        $ct = $isFont ? 'font/ttf' : 'image/png';
        return response()->file($file, ['Content-Type' => $ct, 'Cache-Control' => 'private, max-age=86400']);
    }

    /** Upload de imagem (print de tela) usada nos blocos do Escopo. Retorna o attachment_id. */
    public function escopoImage(Request $request, CrmProposal $crmProposal, AttachmentService $attachments): JsonResponse
    {
        $request->validate(['file' => 'required|file|image|max:5120']);
        $att = $attachments->store($request->user(), [
            'entity_type' => 'CRM_PROPOSAL', 'entity_id' => $crmProposal->id,
            'category' => 'escopo', 'file' => $request->file('file'),
        ], $request);
        return response()->json(['data' => ['attachment_id' => $att->id, 'url' => "/api/v1/crm/proposals/escopo-image/{$att->id}"]]);
    }

    /** Serve a imagem de um bloco do Escopo (Attachment) p/ o preview. */
    public function escopoImageServe(Attachment $attachment)
    {
        abort_unless($attachment->entity_type === 'CRM_PROPOSAL' && $attachment->category === 'escopo', 404);
        try {
            $bytes = app(\App\Attachments\Storage\StorageProvider::class)->get($attachment->storage_path);
        } catch (\Throwable $e) {
            abort(404);
        }
        return response($bytes, 200, ['Content-Type' => $attachment->mime_type ?: 'image/png', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** Serve a imagem do logo (Attachment) p/ o preview. */
    public function logoServe(Attachment $attachment)
    {
        abort_unless($attachment->entity_type === 'CRM_PROPOSAL' && $attachment->category === 'logo', 404);
        try {
            $bytes = app(\App\Attachments\Storage\StorageProvider::class)->get($attachment->storage_path);
        } catch (\Throwable $e) {
            abort(404);
        }
        return response($bytes, 200, ['Content-Type' => $attachment->mime_type ?: 'image/png', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** Simulador: roda a MESMA memória de cálculo (sem persistir nada). */
    public function simular(Request $request, \App\Documents\CrmProposalCalcService $calc): JsonResponse
    {
        $data = $request->validate([
            'modo'   => 'nullable|in:por_hora,valor_fixo',
            'inputs' => 'nullable|array',
        ]);
        $out = $calc->compute($data['inputs'] ?? [], $data['modo'] ?? 'por_hora');
        return response()->json(['data' => $out]);
    }

    /** Dados da Contratada (config GLOBAL via SystemSetting). */
    public function contratadaGet(CrmProposalService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->contratadaConfig()]);
    }

    public function contratadaUpdate(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->type, ['admin', 'administrativo'], true), 403);
        $v = $request->validate([
            'nome' => 'nullable|string|max:255', 'cnpj' => 'nullable|string|max:32',
            'endereco' => 'nullable|string|max:500', 'cep' => 'nullable|string|max:20',
        ]);
        SystemSetting::updateOrCreate(
            ['key' => 'proposta.contratada'],
            ['value' => json_encode($v), 'type' => 'json', 'group' => 'propostas', 'description' => 'Dados da Contratada (Aceite das propostas)']
        );
        return response()->json(['data' => $v]);
    }

    private const TPL_TIPOS = ['bh_fixo', 'bh_mensal', 'on_demand', 'projeto_fechado', 'cloud'];

    /** TEMPLATES de proposta (1 por tipo, GLOBAL) — status de todos os tipos (p/ a tela de gestão). */
    public function templatesList(): JsonResponse
    {
        $rows = SystemSetting::where('key', 'like', 'proposta.template.%')->get(['key', 'value', 'updated_at']);
        $byTipo = [];
        foreach ($rows as $r) {
            $tipo = str_replace('proposta.template.', '', $r->key);
            $d = json_decode($r->value, true) ?: [];
            $byTipo[$tipo] = [
                'tipo' => $tipo,
                'salvo' => true,
                'updated_at' => optional($r->updated_at)->toIso8601String(),
                'secoes' => (isset($d['conteudo']) && is_array($d['conteudo'])) ? array_values(array_keys($d['conteudo'])) : [],
                'tem_memoria' => !empty($d['inputs']),
            ];
        }
        $out = [];
        foreach (self::TPL_TIPOS as $t) {
            $out[] = $byTipo[$t] ?? ['tipo' => $t, 'salvo' => false, 'updated_at' => null, 'secoes' => [], 'tem_memoria' => false];
        }
        return response()->json(['data' => $out]);
    }

    /** Exclui o template de um tipo. */
    public function templateDelete(string $tipo): JsonResponse
    {
        abort_unless(in_array($tipo, self::TPL_TIPOS, true), 404);
        abort_unless(in_array(request()->user()->type, ['admin', 'administrativo'], true), 403);
        SystemSetting::where('key', "proposta.template.$tipo")->delete();
        return response()->json(['data' => true]);
    }

    /** Retorna o template salvo de um tipo (conteudo + inputs + modo_faturamento + defaults + tipo). */
    public function templateGet(string $tipo, CrmProposalService $svc): JsonResponse
    {
        abort_unless(in_array($tipo, self::TPL_TIPOS, true), 404);
        $raw = SystemSetting::where('key', "proposta.template.$tipo")->value('value');
        $tpl = $raw ? (json_decode($raw, true) ?: []) : [];
        return response()->json(['data' => array_merge(
            ['conteudo' => [], 'inputs' => [], 'modo_faturamento' => null],
            $tpl,
            ['tipo' => $tipo, 'salvo' => $raw !== null, 'defaults' => $svc->proposalDefaults($tipo)]
        )]);
    }

    /** Preview (HTML renderizado) do template — p/ "Visualizar" na tela de gestão. */
    public function templatePreview(string $tipo, CrmProposalService $svc): JsonResponse
    {
        abort_unless(in_array($tipo, self::TPL_TIPOS, true), 404);
        $raw = SystemSetting::where('key', "proposta.template.$tipo")->value('value');
        $tpl = $raw ? (json_decode($raw, true) ?: []) : [];
        $data = $svc->previewData([
            'tipo' => $tipo, 'conteudo' => $tpl['conteudo'] ?? [], 'inputs' => $tpl['inputs'] ?? [],
            'modo_faturamento' => $tpl['modo_faturamento'] ?? null, 'codigo' => 'TEMPLATE', 'versao' => 1,
        ]);
        return response()->json(['data' => ['html' => $data['html']]]);
    }

    /** Salva (sobrescreve) o template do tipo. Remove dados específicos do cliente (logo, subprojeto). */
    public function templateSave(Request $request, string $tipo): JsonResponse
    {
        abort_unless(in_array($tipo, self::TPL_TIPOS, true), 404);
        $v = $request->validate([
            'conteudo' => 'nullable|array', 'inputs' => 'nullable|array',
            'modo_faturamento' => 'nullable|in:por_hora,valor_fixo',
        ]);
        $conteudo = (array) ($v['conteudo'] ?? []);
        unset($conteudo['logo_attachment_id']);
        if (isset($conteudo['contrato']) && is_array($conteudo['contrato'])) {
            unset($conteudo['contrato']['is_subproject'], $conteudo['contrato']['parent_project_id'], $conteudo['contrato']['sera_faturado']);
        }
        $payload = ['conteudo' => $conteudo, 'inputs' => (array) ($v['inputs'] ?? []), 'modo_faturamento' => $v['modo_faturamento'] ?? null];
        SystemSetting::updateOrCreate(
            ['key' => "proposta.template.$tipo"],
            ['value' => json_encode($payload), 'type' => 'json', 'group' => 'propostas', 'description' => "Template de proposta ($tipo)"]
        );
        return response()->json(['data' => $payload]);
    }

    private const TIPO_LABELS = [
        'bh_fixo' => 'Banco de Horas Fixo', 'bh_mensal' => 'Banco de Horas Mensal',
        'on_demand' => 'Consultoria Sob Demanda', 'projeto_fechado' => 'Projeto Fechado', 'cloud' => 'Cloud Protheus',
    ];

    private function brlProposta(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    private function propostaDefaultMensagem(): string
    {
        return "Conforme alinhado, encaminhamos nossa proposta comercial para sua avaliação.\n\n"
            . "O documento em anexo detalha o escopo, o investimento, o prazo e as condições do serviço. "
            . "Ficamos à disposição para esclarecer qualquer ponto e seguir com os próximos passos.";
    }

    private function propostaSubject(CrmProposal $p, string $clienteName): string
    {
        return 'Proposta Comercial' . ($p->codigo ? ' ' . $p->codigo : '') . ' — ' . $clienteName;
    }

    private function assinaturaDefaultMensagem(): string
    {
        return "Segue nossa proposta comercial para assinatura eletrônica.\n\n"
            . "Para concluir, clique no botão abaixo e assine de forma rápida e segura. "
            . "Qualquer dúvida, estamos à disposição.";
    }

    private function assinaturaSubject(CrmProposal $p, string $clienteName): string
    {
        return 'Assinatura da Proposta' . ($p->codigo ? ' ' . $p->codigo : '') . ' — ' . $clienteName;
    }

    private function aprovacaoDefaultMensagem(): string
    {
        return "Encaminhamos nossa proposta comercial para sua análise e aprovação.\n\n"
            . "Clique no botão abaixo para revisar o documento e registrar sua aprovação. "
            . "Qualquer ajuste necessário, é só nos retornar.";
    }

    private function aprovacaoSubject(CrmProposal $p, string $clienteName): string
    {
        return 'Aprovação da Proposta' . ($p->codigo ? ' ' . $p->codigo : '') . ' — ' . $clienteName;
    }

    /** Prévia do e-mail de APROVAÇÃO (template editável, remetente = usuário logado; logo ERPSERV + Minutor). */
    public function aprovacaoEmailPreview(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        $sender = $request->user();
        if (!$sender) return response()->json(['message' => 'Sem permissão.'], 403);
        $crmProposal->load(['customer:id,name']);
        $clienteName = $crmProposal->customer?->name ?? 'cliente';
        $mensagemPadrao = $this->aprovacaoDefaultMensagem();
        $mensagem = trim((string) $request->input('mensagem')) ?: $mensagemPadrao;

        $html = view('emails.crm.proposta', [
            'clienteName' => $clienteName, 'senderName' => $sender->name,
            'codigo' => $crmProposal->codigo, 'tipoLabel' => self::TIPO_LABELS[$crmProposal->tipo ?? 'bh_fixo'] ?? null,
            'valorTotal' => $this->brlProposta((float) $crmProposal->total),
            'validade' => optional($crmProposal->data_validade)->format('d/m/Y'),
            'mensagem' => $mensagem, 'portalUrl' => '#', 'withAttachments' => false,
            'ctaLabel' => 'Revisar e aprovar a proposta', 'ctaCaption' => 'Você acessa a proposta completa e registra sua aprovação no portal.',
        ])->render();
        $override = '<style>.erp-light{display:inline-block !important}.erp-dark{display:none !important}</style>';
        $html = str_ireplace('</head>', $override . '</head>', $html);

        $aprovadores = $crmProposal->participants()->where('is_active', true)->get()
            ->filter(fn ($x) => $x->hasRole('approver'))
            ->map(fn ($x) => ['nome' => $x->name, 'email' => $x->email, 'aprovou' => $x->approved_at !== null])->values();

        return response()->json(['data' => [
            'html' => $html,
            'mensagem_padrao' => $mensagemPadrao,
            'assunto_padrao' => $this->aprovacaoSubject($crmProposal, $clienteName),
            'aprovadores' => $aprovadores,
            'remetente' => ['nome' => $sender->name, 'email' => $sender->email],
        ]]);
    }

    /**
     * P-E.2.x — Prévia do e-mail de ASSINATURA (template editável, remetente = usuário logado).
     * Mesma experiência do envio por e-mail: mensagem padrão editável + assunto + prévia ao vivo.
     */
    public function assinaturaEmailPreview(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        $sender = $request->user();
        if (!$sender) return response()->json(['message' => 'Sem permissão.'], 403);
        $crmProposal->load(['customer:id,name']);
        $clienteName = $crmProposal->customer?->name ?? 'cliente';
        $mensagemPadrao = $this->assinaturaDefaultMensagem();
        $mensagem = trim((string) $request->input('mensagem')) ?: $mensagemPadrao;

        $html = view('emails.crm.proposta', [
            'clienteName' => $clienteName, 'senderName' => $sender->name,
            'codigo' => $crmProposal->codigo, 'tipoLabel' => self::TIPO_LABELS[$crmProposal->tipo ?? 'bh_fixo'] ?? null,
            'valorTotal' => $this->brlProposta((float) $crmProposal->total),
            'validade' => optional($crmProposal->data_validade)->format('d/m/Y'),
            'mensagem' => $mensagem, 'portalUrl' => '#', 'withAttachments' => false,
            'ctaLabel' => 'Assinar a proposta', 'ctaCaption' => 'Você abre a proposta e assina diretamente no portal, com segurança.',
        ])->render();
        $override = '<style>.erp-light{display:inline-block !important}.erp-dark{display:none !important}</style>';
        $html = str_ireplace('</head>', $override . '</head>', $html);

        $signatarios = $crmProposal->participants()->where('is_active', true)->get()
            ->filter(fn ($x) => $x->hasRole('signer'))
            ->map(fn ($x) => ['nome' => $x->name, 'email' => $x->email, 'parte' => $x->parte])->values();

        return response()->json(['data' => [
            'html' => $html,
            'mensagem_padrao' => $mensagemPadrao,
            'assunto_padrao' => $this->assinaturaSubject($crmProposal, $clienteName),
            'signatarios' => $signatarios,
            'remetente' => ['nome' => $sender->name, 'email' => $sender->email],
        ]]);
    }

    /** Prévia do e-mail da proposta (template real), atualizada ao vivo conforme o usuário edita a mensagem. */
    public function emailPreview(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        $sender = $request->user();
        if (!$sender) {
            return response()->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        $crmProposal->load(['customer:id,name', 'opportunity.contato:id,email,name']);
        $tipo        = $crmProposal->tipo ?: 'bh_fixo';
        $clienteName = $crmProposal->customer?->name ?? 'cliente';
        $mensagemPadrao = $this->propostaDefaultMensagem();
        $mensagem    = trim((string) $request->input('mensagem')) ?: $mensagemPadrao;

        $html = view('emails.crm.proposta', [
            'clienteName'     => $clienteName,
            'senderName'      => $sender->name,
            'codigo'          => $crmProposal->codigo,
            'tipoLabel'       => self::TIPO_LABELS[$tipo] ?? null,
            'valorTotal'      => $this->brlProposta((float) $crmProposal->total),
            'validade'        => optional($crmProposal->data_validade)->format('d/m/Y'),
            'mensagem'        => $mensagem,
            'portalUrl'       => '#',   // prévia: botão ilustrativo (o link real é gerado no envio)
            'withAttachments' => false,
        ])->render();
        // Prévia: força o logo claro (o swap de dark-mode mostraria o branco, invisível aqui).
        $override = '<style>.erp-light{display:inline-block !important}.erp-dark{display:none !important}</style>';
        $html = str_ireplace('</head>', $override . '</head>', $html);

        $sugeridos = [];
        $contato = optional($crmProposal->opportunity)->contato;
        if ($contato && filled($contato->email)) {
            $sugeridos[] = $contato->email;
        }

        return response()->json(['data' => [
            'html'            => $html,
            'mensagem_padrao' => $mensagemPadrao,
            'assunto_padrao'  => $this->propostaSubject($crmProposal, $clienteName),
            'sugeridos'       => array_values(array_unique($sugeridos)),
        ]]);
    }

    /** Envia a proposta por e-mail COMO o usuário logado (Graph Send As), com o PDF em anexo. */
    public function enviarEmail(Request $request, CrmProposal $crmProposal, CrmProposalService $svc): JsonResponse
    {
        $sender = $request->user();
        if (!$sender) {
            return response()->json(['success' => false, 'message' => 'Sem permissão para enviar a proposta.'], 403);
        }
        if (!$sender->email) {
            return response()->json(['success' => false, 'message' => 'Seu usuário não tem e-mail cadastrado para usar como remetente.'], 422);
        }
        if (!$crmProposal->codigo || !$crmProposal->calc_id) {
            return response()->json(['message' => 'Proposta sem código/memória de cálculo — recrie pelo fluxo novo (com tipo).'], 422);
        }

        $request->validate([
            'mensagem' => 'nullable|string',
            'assunto'  => 'nullable|string|max:255',
            'emails'   => 'required|array|min:1',
            'emails.*' => 'email',
        ], [
            'emails.required' => 'Informe ao menos um e-mail de destino antes de enviar.',
            'emails.min'      => 'Informe ao menos um e-mail de destino antes de enviar.',
            'emails.*.email'  => 'Um dos e-mails informados é inválido.',
        ]);

        $crmProposal->load(['customer:id,name']);
        $tipo        = $crmProposal->tipo ?: 'bh_fixo';
        $clienteName = $crmProposal->customer?->name ?? 'cliente';

        $to = array_values(array_unique(array_filter($request->input('emails') ?: [])));
        if (empty($to)) {
            return response()->json(['success' => false, 'message' => 'Nenhum destinatário: informe ao menos um e-mail.'], 422);
        }
        $financeiroCc = (string) (config('mail.financeiro_cc') ?? '');
        $cc = array_values(array_diff(array_filter([$financeiroCc]), $to));

        $mensagem = trim((string) $request->input('mensagem')) ?: $this->propostaDefaultMensagem();
        $subject  = trim((string) $request->input('assunto')) ?: $this->propostaSubject($crmProposal, $clienteName);

        // Gera (render síncrono) o PDF — necessário p/ o Portal servir a proposta — e
        // cria/reusa o LINK público. O e-mail leva o LINK (sem anexo de PDF).
        $doc = $svc->gerarDocumento($crmProposal, $sender, true);
        $share = $this->shareParaEnvio($crmProposal, $doc->id, $sender->id, $to[0] ?? null);
        // P-C.1 — (re)snapshot das seções HTML do Portal no envio (espelha o PDF gerado).
        app(\App\Services\ProposalSectionService::class)->sync($crmProposal);

        // Participante PRINCIPAL automático: contato principal da oportunidade (ou 1º destinatário),
        // papéis Approver+Signer. O e-mail da proposta JÁ leva o link individual dele (?pt).
        $crmProposal->loadMissing('opportunity.contato');
        $contato = $crmProposal->opportunity?->contato;
        $primName  = trim((string) ($contato?->name ?? '')) ?: ($to[0] ?? '');
        $primEmail = (string) ($contato?->email ?: ($to[0] ?? ''));
        $principal = app(\App\Services\ProposalParticipantService::class)
            ->garantirPrincipal($crmProposal, $primName, $primEmail, $sender->id);

        $portalUrl = $this->portalBase() . '/p/' . $share->token
            . ($principal ? '?pt=' . $principal->participant_token : '');

        // Envia COMO o remetente (Graph Send As) quando configurado; senão, conta padrão (fallback).
        $mc = \App\Services\SenderMailer::for(
            $sender,
            (string) config('mail.fechamento_cliente_mailer', 'nfe'),
            (string) config('mail.fechamento_cliente_from', config('mail.from.address')),
            config('mail.fechamento_cliente_from_name', config('mail.from.name', 'ERPSERV Consultoria')),
        );

        try {
            $mailable = new \App\Mail\CrmProposalMail(
                clienteName:  $clienteName,
                senderName:   $sender->name,
                subjectLine:  $subject,
                portalUrl:    $portalUrl,
                codigo:       $crmProposal->codigo,
                tipoLabel:    self::TIPO_LABELS[$tipo] ?? null,
                valorTotal:   $this->brlProposta((float) $crmProposal->total),
                validade:     optional($crmProposal->data_validade)->format('d/m/Y'),
                senderEmail:  $sender->email,
                financeiroCc: $financeiroCc ?: null,
                mensagem:     $mensagem,
                withAttachments: false,   // Portal de Propostas: o cliente acessa pelo LINK.
                fromAddress:  $mc['from_address'],
                fromName:     $mc['from_name'],
            );

            if (\App\Services\GraphMailer::enabled() && filled($sender->email)) {
                \App\Services\GraphMailer::sendAs($sender->email, $to, $cc, $subject, $mailable->render(), []);
            } else {
                \Illuminate\Support\Facades\Mail::mailer($mc['mailer'])->to($to)->cc($cc)->send($mailable);
            }

            // Status oficial → "enviada"; evento ENVIADO no Document; marco na timeline.
            if (in_array($crmProposal->status, ['em_elaboracao', 'reativada'], true)) {
                $crmProposal->update(['status' => 'enviada']);
            }
            if (in_array($doc->status, ['gerado', 'em_elaboracao'], true)) {
                $doc->update(['status' => 'enviada']);
            }
            $doc->logEvent(DocumentEvent::TYPE_ENVIADO, ['share_id' => $share->id, 'to' => $to], $sender->id);
            if ($crmProposal->opportunity_id) {
                CrmOpportunityEvent::log((int) $crmProposal->opportunity_id, 'note', [
                    'to_value' => "Proposta {$crmProposal->codigo} enviada (link) para " . implode(', ', $to),
                    'meta'     => ['kind' => 'proposta_enviada', 'share_id' => $share->id],
                ]);
            }
            \Log::info('Proposta enviada por e-mail (link)', ['proposta' => $crmProposal->id, 'share' => $share->id, 'remetente' => $sender->id, 'to' => $to, 'cc' => $cc]);
        } catch (\Throwable $e) {
            \Log::error('Falha ao enviar proposta por e-mail', ['proposta' => $crmProposal->id, 'remetente' => $sender->id, 'erro' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Falha ao enviar o e-mail: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success'    => true,
            'portal_url' => $portalUrl,
            'message'    => 'Proposta enviada para ' . implode(', ', $to) . (!empty($cc) ? ' (cópia: ' . implode(', ', $cc) . ')' : '') . '.',
        ]);
    }

    /** Base (origem do frontend) p/ montar o link do Portal. Configurável via FRONTEND_URL. */
    private function portalBase(): string
    {
        return rtrim((string) (env('FRONTEND_URL') ?: config('app.frontend_url') ?: config('app.url') ?: 'https://app.minutor.com.br'), '/');
    }

    /** Reusa o share ativo deste PDF (link estável por versão) ou cria um novo. Marca o envio. */
    private function shareParaEnvio(CrmProposal $p, int $documentId, ?int $senderId, ?string $destinatario): CrmProposalShare
    {
        $expira = $p->data_validade ? $p->data_validade->copy()->endOfDay()->addDays(7) : now()->addDays(30);
        $share = CrmProposalShare::where('proposal_id', $p->id)->where('document_id', $documentId)
            ->whereNull('revoked_at')->orderByDesc('id')->first();
        if (!$share) {
            $share = CrmProposalShare::create([
                'proposal_id' => $p->id, 'document_id' => $documentId, 'token' => CrmProposalShare::novoToken(),
                'destinatario' => $destinatario, 'enviado_por' => $senderId, 'sent_at' => now(), 'expires_at' => $expira,
            ]);
        } else {
            $share->update(['sent_at' => $share->sent_at ?? now(), 'destinatario' => $destinatario ?: $share->destinatario, 'expires_at' => $expira, 'enviado_por' => $share->enviado_por ?: $senderId]);
        }
        return $share;
    }

    /** POST /crm/proposals/{p}/share — cria/reusa o link público (botão "Gerar/copiar link"). */
    public function criarShare(Request $request, CrmProposal $crmProposal, CrmProposalService $svc): JsonResponse
    {
        $sender = $request->user();
        if (!$crmProposal->codigo || !$crmProposal->calc_id) {
            return response()->json(['message' => 'Proposta sem código/memória de cálculo.'], 422);
        }
        $doc = $svc->gerarDocumento($crmProposal, $sender, true);
        $share = $this->shareParaEnvio($crmProposal, $doc->id, $sender->id, $request->input('destinatario'));
        return response()->json(['data' => [
            'token' => $share->token, 'path' => '/p/' . $share->token, 'url' => $this->portalBase() . '/p/' . $share->token,
            'expires_at' => optional($share->expires_at)->toIso8601String(),
        ]]);
    }

    /** POST /crm/proposals/{p}/shares/{share}/revoke — revoga um link. */
    public function revokeShare(CrmProposal $crmProposal, CrmProposalShare $share): JsonResponse
    {
        abort_unless($share->proposal_id === $crmProposal->id, 404);
        if (!$share->revoked_at) $share->update(['revoked_at' => now()]);
        return response()->json(['data' => ['revoked' => true]]);
    }

    /** GET /crm/proposals/{p}/engajamento — indicadores comerciais do tracking (Fase 2). */
    /** GET /crm/proposals/{p}/analytics — P-E.1 analytics operacionais (as 4 perguntas + detalhe). */
    public function analytics(CrmProposal $crmProposal): JsonResponse
    {
        $data = app(\App\Services\ProposalAnalyticsService::class)->operational($crmProposal);
        $fb = \App\Models\CrmProposalDiagnosticFeedback::with('user:id,name')
            ->where('crm_proposal_id', $crmProposal->id)->orderByDesc('id')->first();
        $data['diagnostico_feedback'] = $fb ? ['resposta' => $fb->resposta, 'comentario' => $fb->comentario, 'por' => $fb->user?->name, 'em' => optional($fb->created_at)->toIso8601String()] : null;
        return response()->json(['data' => $data]);
    }

    /** POST /crm/proposals/{p}/diagnostico-feedback — V1.2: "o diagnóstico está correto?" (sim/parcial/nao). */
    public function diagnosticoFeedback(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        $v = $request->validate(['resposta' => 'required|in:sim,parcial,nao', 'comentario' => 'nullable|string|max:1000']);
        \App\Models\CrmProposalDiagnosticFeedback::create([
            'crm_proposal_id' => $crmProposal->id, 'user_id' => $request->user()->id,
            'resposta' => $v['resposta'], 'comentario' => $v['comentario'] ?? null,
        ]);
        return response()->json(['data' => ['ok' => true]]);
    }

    public function engajamento(CrmProposal $crmProposal): JsonResponse
    {
        $shares = $crmProposal->shares()->with('enviadoPor:id,name')->orderByDesc('id')->get();
        $enviadaEm   = $shares->whereNotNull('sent_at')->min('sent_at');
        $primeira    = $shares->whereNotNull('first_viewed_at')->min('first_viewed_at');
        $ultima      = $shares->whereNotNull('last_viewed_at')->max('last_viewed_at');
        $aberturas   = (int) $shares->sum('view_count');
        $aceitaEm    = $shares->whereNotNull('accepted_at')->max('accepted_at');
        $recusadaEm  = $shares->whereNotNull('rejected_at')->max('rejected_at');
        $refInteracao = $ultima ?: $enviadaEm;
        $diasSemInteracao = $refInteracao ? \Carbon\Carbon::parse($refInteracao)->diffInDays(now()) : null;
        $minutos = $primeira && $enviadaEm ? \Carbon\Carbon::parse($enviadaEm)->diffInMinutes(\Carbon\Carbon::parse($primeira)) : null;

        $ativo = $shares->first(fn ($s) => $s->isAtivo());
        // Linha do tempo de tracking (eventos do Document desta proposta).
        $eventos = [];
        if ($crmProposal->document_id) {
            $eventos = DocumentEvent::where('document_id', $crmProposal->document_id)
                ->whereIn('event_type', [DocumentEvent::TYPE_ENVIADO, DocumentEvent::TYPE_VISUALIZADO, DocumentEvent::TYPE_REVISITADO, DocumentEvent::TYPE_BAIXADO, DocumentEvent::TYPE_ACEITO, DocumentEvent::TYPE_RECUSADO, DocumentEvent::TYPE_EXPIRADO, DocumentEvent::TYPE_CONTRATO_GERADO])
                ->orderByDesc('sequence_number')->limit(40)->get(['event_type', 'created_at', 'meta'])
                ->map(fn ($e) => ['tipo' => $e->event_type, 'em' => optional($e->created_at)->toIso8601String()])->all();
        }

        // Mapa de calor: tempo REAL de leitura por página do deck (crm_proposal_page_views).
        // Soma a duração por página entre todas as visitas/participantes.
        $paginasRows = \App\Models\CrmProposalPageView::where('crm_proposal_id', $crmProposal->id)
            ->selectRaw('page, COALESCE(SUM(duration_seconds),0) as segundos, COUNT(*) as views')
            ->groupBy('page')
            ->orderBy('page')
            ->get();
        $paginas = $paginasRows->map(fn ($r) => [
            'pagina'   => (int) $r->page,
            'segundos' => (int) $r->segundos,
            'views'    => (int) $r->views,
        ])->all();
        $totalPaginas = (int) \App\Models\CrmProposalPageView::where('crm_proposal_id', $crmProposal->id)->max('total_pages');

        return response()->json(['data' => [
            'status'          => $crmProposal->status,
            'paginas'         => $paginas,
            'total_paginas'   => $totalPaginas,
            'enviada_em'      => optional($enviadaEm)->toIso8601String(),
            'aberta'          => $primeira !== null,
            'primeira_abertura' => optional($primeira)->toIso8601String(),
            'ultima_abertura' => optional($ultima)->toIso8601String(),
            'total_aberturas' => $aberturas,
            'revisitas'       => max(0, $aberturas - 1),
            'minutos_ate_abrir' => $minutos,
            'dias_sem_interacao' => $diasSemInteracao,
            'tempo_leitura_seg' => (int) $shares->sum('read_seconds'),
            'aceita_em'       => optional($aceitaEm)->toIso8601String(),
            'recusada_em'     => optional($recusadaEm)->toIso8601String(),
            'motivo_recusa'   => $shares->whereNotNull('rejected_at')->sortByDesc('rejected_at')->first()?->reject_reason,
            'link_ativo'      => $ativo ? ['url' => $this->portalBase() . '/p/' . $ativo->token, 'path' => '/p/' . $ativo->token, 'token' => $ativo->token, 'expira_em' => optional($ativo->expires_at)->toIso8601String()] : null,
            'shares'          => $shares->map(fn ($s) => [
                'id' => $s->id, 'token' => $s->token, 'destinatario' => $s->destinatario,
                'enviado_por' => $s->enviadoPor?->name, 'sent_at' => optional($s->sent_at)->toIso8601String(),
                'revogado' => $s->isRevogado(), 'expirado' => $s->isExpirado(),
                'view_count' => $s->view_count, 'last_viewed_at' => optional($s->last_viewed_at)->toIso8601String(),
            ])->all(),
            'eventos'         => $eventos,
        ]]);
    }

    /** POST /crm/proposals/{p}/converter — gera o CONTRATO a partir da proposta aprovada (mesmo código). */
    /**
     * GET /crm/proposals/handoff — fila de HANDOFF para Serviços: propostas LIBERADAS aguardando
     * a criação do contrato operacional. São os "cards" que aparecem no Kanban de Serviços.
     */
    public function handoff(Request $request): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        $list = CrmProposal::where('status', 'liberada')->with(['customer:id,name', 'liberadoPor:id,name'])
            ->orderBy('liberado_em')->get()->map(fn ($p) => [
                'id' => $p->id, 'codigo' => $p->codigo, 'cliente' => $p->customer?->name,
                'tipo' => $p->tipo, 'valor' => 'R$ ' . number_format((float) $p->total, 2, ',', '.'),
                'liberado_em' => optional($p->liberado_em)->toIso8601String(),
                'liberado_por' => $p->liberadoPor?->name,
                'dias_aguardando' => $p->liberado_em ? (int) $p->liberado_em->diffInDays(now()) : null,
                'observacao' => $p->liberacao_observacao,
            ]);
        return response()->json(['data' => $list]);
    }

    public function converter(Request $request, CrmProposal $crmProposal, CrmProposalService $svc): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão para criar o contrato operacional.'], 403);
        $v = $request->validate([
            'categoria'        => 'nullable|in:projeto,sustentacao',
            'contract_type_id' => 'nullable|exists:contract_types,id',
            'service_type_id'  => 'nullable|exists:service_types,id',
            'tipo_faturamento' => 'nullable|string|max:40',
        ]);
        $crmProposal->loadMissing('customer', 'opportunity');
        // CNPJ obrigatório p/ emitir contrato (mesma regra do fluxo "ganho").
        if ($crmProposal->customer && empty($crmProposal->customer->cgc)) {
            return response()->json(['message' => 'Informe o CNPJ/CPF da empresa antes de gerar o contrato.', 'code' => 'CGC_REQUIRED'], 422);
        }
        try {
            $contract = $svc->converter($crmProposal, array_filter($v, fn ($x) => $x !== null), $request->user());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => [
            'contract_id'   => $contract->id,
            'codigo'        => $crmProposal->codigo,
            'kanban_status' => $contract->kanban_status,
            'status'        => $crmProposal->fresh()->status,
        ]], 201);
    }

    /** Gera (render síncrono via Gotenberg) o PDF da proposta com o artwork + dados dinâmicos. */
    public function gerar(Request $request, CrmProposal $crmProposal, CrmProposalService $svc): JsonResponse
    {
        if (!$crmProposal->codigo || !$crmProposal->calc_id) {
            return response()->json(['message' => 'Proposta sem código/memória de cálculo — recrie pelo fluxo novo (com tipo).'], 422);
        }
        $doc = $svc->gerarDocumento($crmProposal, $request->user(), true);
        return response()->json(['data' => [
            'document_id'  => $doc->id,
            'status'       => $doc->status,
            'download_url' => "/documents/{$doc->id}/download",
        ]]);
    }

    // ─── Liberação Operacional PROPOSAL-CENTRIC ──────────────────────────────────

    private function podeGerir($request): bool
    {
        $u = $request->user();
        return $u && in_array($u->type, ['admin', 'administrativo'], true);
    }

    /** Proposta pode ser LIBERADA comercialmente? (cliente já assinou no Portal) */
    private const STATUS_PRE_LIBERACAO = ['assinada', 'aprovada', 'aguardando_liberacao'];

    /**
     * GET /crm/proposals/{p}/liberacao — painel de LIBERAÇÃO COMERCIAL.
     * CRM termina no comercial: SEM checklist/SLA/ownership/projeto. Liberar = autorizar o handoff
     * para Serviços (Kanban de Contratos), onde o contrato operacional nasce.
     */
    public function liberacao(CrmProposal $crmProposal): JsonResponse
    {
        $crmProposal->loadMissing(['liberadoPor:id,name', 'bloqueadoPor:id,name', 'lossReason:id,name', 'customer:id,name,cgc,umbrella_contract_numero,umbrella_contract_assinatura,umbrella_contract_vigencia']);
        return response()->json(['data' => [
            'status'        => $crmProposal->status,
            'perda'         => $crmProposal->perdida_em ? ['motivo' => $crmProposal->lossReason?->name, 'observacao' => $crmProposal->motivo_perda_obs, 'perdida_em' => optional($crmProposal->perdida_em)->toIso8601String()] : null,
            'pode_liberar'  => $crmProposal->status === 'assinada' || in_array($crmProposal->status, ['aprovada', 'aguardando_liberacao'], true),
            'liberacao'     => $crmProposal->liberado_em ? ['liberado_por' => $crmProposal->liberadoPor?->name, 'liberado_em' => optional($crmProposal->liberado_em)->toIso8601String(), 'observacao' => $crmProposal->liberacao_observacao] : null,
            'bloqueio'      => $crmProposal->bloqueado_em ? ['bloqueado_por' => $crmProposal->bloqueadoPor?->name, 'bloqueado_em' => optional($crmProposal->bloqueado_em)->toIso8601String(), 'motivo' => $crmProposal->motivo_bloqueio] : null,
            'umbrella'      => $crmProposal->umbrella_ref ?: optional($crmProposal->customer)->umbrella_contract_numero,
            'juridica'      => [
                'umbrella_ref'  => $crmProposal->umbrella_ref,
                'cliente'       => optional($crmProposal->customer)->name,
                'cnpj'          => optional($crmProposal->customer)->cgc,
                'contrato_numero'    => optional($crmProposal->customer)->umbrella_contract_numero,
                'contrato_assinatura' => optional($crmProposal->customer)->umbrella_contract_assinatura,
                'contrato_vigencia'   => optional($crmProposal->customer)->umbrella_contract_vigencia,
            ],
        ]]);
    }

    /** POST /crm/proposals/{p}/checklist — marca/desmarca item. */
    public function checklistMarcar(Request $request, CrmProposal $crmProposal, \App\Services\ProposalReleaseChecklistService $svc): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        $v = $request->validate(['item_key' => 'required|string|max:48', 'checked' => 'required|boolean']);
        $crmProposal->loadMissing('customer');
        if ($v['item_key'] === \App\Services\ProposalReleaseChecklistService::ITEM_JURIDICO && $svc->temCobertura($crmProposal)) {
            return response()->json(['message' => 'Item automático (há Contrato Guarda-Chuva ativo).'], 422);
        }
        $svc->instanciar($crmProposal);
        $item = $svc->marcar($crmProposal, $v['item_key'], $v['checked'], $request->user()->id);
        if (!$item) return response()->json(['message' => 'Item não encontrado.'], 404);
        return response()->json(['data' => ['item_key' => $item->item_key, 'checked' => $item->checked, 'pode_liberar' => $svc->podeLiberar($crmProposal)]]);
    }

    /**
     * POST /crm/proposals/{p}/liberar — LIBERAÇÃO COMERCIAL (assinada → liberada).
     * Apenas autoriza o handoff para Serviços. NÃO cria projeto/atividade/cronograma — só disponibiliza
     * a proposta liberada para o Kanban de Serviços, onde a operação assume.
     */
    public function liberar(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão para liberar.'], 403);
        $v = $request->validate(['observacao' => 'nullable|string|max:1000']);
        if (!in_array($crmProposal->status, self::STATUS_PRE_LIBERACAO, true)) {
            return response()->json(['message' => 'A proposta precisa estar ASSINADA para ser liberada.'], 422);
        }
        $crmProposal->update([
            'status' => 'liberada', 'liberado_por' => $request->user()->id,
            'liberado_em' => now(), 'liberacao_observacao' => $v['observacao'] ?? null,
        ]);
        if ($crmProposal->opportunity_id) {
            CrmOpportunityEvent::log((int) $crmProposal->opportunity_id, 'note', [
                'to_value' => "Proposta {$crmProposal->codigo} liberada — encaminhada ao Kanban de Serviços",
                'meta' => ['kind' => 'liberada', 'observacao' => $v['observacao'] ?? null],
            ]);
        }
        return response()->json(['data' => ['status' => $crmProposal->status, 'liberado_em' => optional($crmProposal->liberado_em)->toIso8601String()]]);
    }

    /** Link do portal para um participante (share ativo + token do participante). */
    private function portalParticipanteLink(CrmProposal $p, \App\Models\CrmProposalParticipant $part): ?string
    {
        $share = $p->shares()->whereNull('revoked_at')->orderByDesc('id')->first();
        return $share ? $this->portalBase() . '/p/' . $share->token . '?pt=' . $part->participant_token : null;
    }

    /** GET /crm/proposals/{p}/participantes — lista de participantes + status/analytics. */
    public function participantes(CrmProposal $crmProposal): JsonResponse
    {
        $list = $crmProposal->participants()->orderByDesc('is_active')->orderBy('id')->get()->map(fn ($x) => [
            'id' => $x->id, 'name' => $x->name, 'email' => $x->email, 'roles' => $x->roles,
            'cargo' => $x->cargo, 'parte' => $x->parte,
            'status' => $x->statusLabel(), 'is_active' => $x->is_active,
            'approved' => $x->approved_at !== null, 'signed' => $x->signed_at !== null,
            'invited_at' => optional($x->invited_at)->toIso8601String(),
            'last_invite_at' => optional($x->last_invite_at)->toIso8601String(), 'invite_count' => $x->invite_count,
            'accepted_at' => optional($x->accepted_at)->toIso8601String(),
            'first_view' => optional($x->viewed_at)->toIso8601String(),
            'last_access_at' => optional($x->last_access_at)->toIso8601String(), 'access_count' => $x->access_count,
            'link' => $this->portalParticipanteLink($crmProposal, $x),
            // P-E.2.4 — evidências da assinatura (registro), sem o traço pesado (vem no comprovante).
            'approved_at' => optional($x->approved_at)->toIso8601String(),
            'signed_at' => optional($x->signed_at)->toIso8601String(),
            'sign_name' => $x->sign_name, 'sign_cpf' => $x->sign_cpf, 'sign_cargo' => $x->sign_cargo,
            'sign_ip' => $x->sign_ip, 'sign_doc_hash' => $x->sign_doc_hash, 'sign_doc_version' => $x->sign_doc_version,
            'has_sign_image' => filled($x->sign_image),
        ]);
        return response()->json(['data' => $list]);
    }

    /**
     * POST /crm/proposals/{p}/participantes/{part}/clicksign — P-E.2.4: o editor abre o Clicksign EMBEDADO
     * de um assinante (ex.: Contratada). Garante o envelope (com os signers não-assinados) e devolve o sign_url do part.
     */
    public function iniciarClicksignParticipante(Request $request, CrmProposal $crmProposal, \App\Models\CrmProposalParticipant $part): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        abort_unless($part->crm_proposal_id === $crmProposal->id, 404);
        if (!$part->hasRole('signer')) return response()->json(['message' => 'Este participante não é assinante.'], 422);
        if ($part->signed_at) return response()->json(['data' => ['ja_assinou' => true]]);
        if (in_array($crmProposal->status, ['reprovada', 'cancelada', 'expirada', 'convertida', 'liberada'], true)) {
            return response()->json(['message' => 'Proposta não disponível para assinatura.'], 422);
        }
        $cs = app(\App\Services\Clicksign\ClicksignService::class);
        $env = \App\Models\ClicksignEnvelope::with('signers')->where('crm_proposal_id', $crmProposal->id)->where('is_active', true)->orderByDesc('id')->first();
        if (!$env) {
            if (!$crmProposal->document_id) return response()->json(['message' => 'Gere o PDF da proposta antes de enviar à assinatura.'], 422);
            $signers = $crmProposal->participants()->where('is_active', true)->get()
                ->filter(fn ($x) => $x->hasRole('signer') && !$x->signed_at)
                ->map(fn ($x) => ['name' => $x->name, 'email' => $x->email, 'documentation' => $x->sign_cpf, 'crm_proposal_participant_id' => $x->id])->values()->all();
            if (empty($signers)) return response()->json(['message' => 'Nenhum assinante pendente.'], 422);
            try {
                $env = $cs->enviarProposta($crmProposal, $signers, ['subject' => 'Assinatura — Proposta ' . ($crmProposal->codigo ?: $crmProposal->id)], $request->user());
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $crmProposal->update(['status' => 'aguardando_assinatura']);
        } else {
            // Envelope já existia → reenvia a notificação (e-mail) para o assinante receber o link.
            try { $cs->reenviarNotificacao($env); } catch (\Throwable $e) { /* segue */ }
        }
        $signer = $env->signers->firstWhere('crm_proposal_participant_id', $part->id);
        $url = $signer?->sign_url;
        // v3: se não há URL p/ iframe (embedded depende do SDK/request_signature_key), o Clicksign enviou o link por e-mail.
        if (!$url) return response()->json(['data' => ['por_email' => true, 'email' => $part->email, 'stub' => $cs->usandoStub()]]);
        return response()->json(['data' => ['sign_url' => $url, 'stub' => $cs->usandoStub()]]);
    }

    /** GET /crm/proposals/{p}/participantes/{part}/comprovante — registro completo da assinatura (inclui o traço). */
    public function comprovanteAssinatura(Request $request, CrmProposal $crmProposal, \App\Models\CrmProposalParticipant $part): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        abort_unless($part->crm_proposal_id === $crmProposal->id, 404);
        if (!$part->signed_at) return response()->json(['message' => 'Este participante ainda não assinou.'], 422);
        return response()->json(['data' => [
            'nome' => $part->sign_name ?: $part->name, 'email' => $part->email,
            'cpf' => $part->sign_cpf, 'cargo' => $part->sign_cargo, 'parte' => $part->parte,
            'assinado_em' => optional($part->signed_at)->toIso8601String(),
            'ip' => $part->sign_ip, 'user_agent' => $part->sign_user_agent,
            'doc_hash' => $part->sign_doc_hash, 'doc_version' => $part->sign_doc_version,
            'image' => $part->sign_image, 'metodo' => $part->sign_image ? 'minutor_traco' : 'minutor_aceite',
            'codigo' => $crmProposal->codigo,
        ]]);
    }

    /** POST /crm/proposals/{p}/participantes — comercial adiciona um participante. */
    public function adicionarParticipante(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        $v = $request->validate([
            'name' => 'required|string|max:160', 'email' => 'required|email',
            'roles' => 'required|array|min:1', 'roles.*' => 'in:viewer,reviewer,approver,signer',
            'cargo' => 'nullable|string|max:120', 'parte' => 'nullable|in:contratada,contratante',
        ]);
        $part = app(\App\Services\ProposalParticipantService::class)->adicionar($crmProposal, $v['name'], $v['email'], $v['roles'], $request->user()->id);
        $parte = $v['parte'] ?? ($part->parte ?: (in_array('signer', $part->roles ?? [], true) ? 'contratante' : null));
        $part->update(['cargo' => $v['cargo'] ?? $part->cargo, 'parte' => $parte]);
        // P-E.2.2 — grava no caderno do cliente para reaparecer nas próximas propostas.
        if ($crmProposal->customer_id) {
            \App\Models\CrmCustomerSigner::lembrar((int) $crmProposal->customer_id, $part->name, $part->email, (array) $part->roles, $part->cargo, $part->parte);
        }
        // Auto-cura: novo assinante em proposta já 'assinada' rebaixa p/ aguardando_assinatura (libera a assinatura pendente).
        app(\App\Services\ProposalParticipantService::class)->recomputarAssinatura($crmProposal->fresh(['participants']));
        return response()->json(['data' => ['id' => $part->id, 'link' => $this->portalParticipanteLink($crmProposal, $part)]], 201);
    }

    /** PUT /crm/proposals/{p}/participantes/{part} — ajusta papéis/parte/cargo (papel combinado, remover papel). */
    public function atualizarParticipante(Request $request, CrmProposal $crmProposal, \App\Models\CrmProposalParticipant $part): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        abort_unless($part->crm_proposal_id === $crmProposal->id, 404);
        $v = $request->validate([
            'roles' => 'sometimes|array', 'roles.*' => 'in:viewer,reviewer,approver,signer',
            'name' => 'sometimes|string|max:160', 'cargo' => 'nullable|string|max:120',
            'cpf' => 'nullable|string|max:20', 'parte' => 'nullable|in:contratada,contratante',
        ]);
        $svc = app(\App\Services\ProposalParticipantService::class);
        if (array_key_exists('roles', $v)) {
            $roles = array_values(array_unique($v['roles']));
            // Sem nenhum papel → desativa o participante (não exclui histórico).
            if (empty($roles)) { $svc->desativar($part, $request->user()->id); return response()->json(['data' => ['id' => $part->id, 'is_active' => false]]); }
            $part->roles = $roles;
        }
        if (array_key_exists('name', $v) && trim((string) $v['name']) !== '') $part->name = trim($v['name']);
        if (array_key_exists('cargo', $v)) $part->cargo = $v['cargo'];
        if (array_key_exists('cpf', $v)) $part->sign_cpf = $v['cpf'] ?: null; // documentação p/ a assinatura (Clicksign)
        if (array_key_exists('parte', $v)) $part->parte = $v['parte'];
        if (!in_array('signer', (array) $part->roles, true)) $part->parte = null; // parte só faz sentido p/ assinante
        $part->save();
        $svc->recomputarAssinatura($crmProposal->fresh(['participants']));
        if ($crmProposal->customer_id && $part->is_active) {
            \App\Models\CrmCustomerSigner::lembrar((int) $crmProposal->customer_id, $part->name, $part->email, (array) $part->roles, $part->cargo, $part->parte);
        }
        return response()->json(['data' => ['id' => $part->id, 'roles' => $part->roles, 'parte' => $part->parte, 'cargo' => $part->cargo]]);
    }

    /** GET /crm/proposals/{p}/caderno-cliente — participantes salvos do cliente (memória), p/ incluir/excluir. */
    public function cadernoCliente(CrmProposal $crmProposal): JsonResponse
    {
        if (!$crmProposal->customer_id) return response()->json(['data' => []]);
        $list = \App\Models\CrmCustomerSigner::where('customer_id', $crmProposal->customer_id)->where('is_active', true)
            ->orderBy('name')->get()->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'email' => $s->email, 'cargo' => $s->cargo, 'parte' => $s->parte, 'roles' => $s->roles,
            ]);
        return response()->json(['data' => $list]);
    }

    /** DELETE /crm/customer-signers/{signer} — remove do caderno do cliente (não afeta propostas já criadas). */
    public function excluirCadernoCliente(Request $request, \App\Models\CrmCustomerSigner $signer): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        $signer->update(['is_active' => false]);
        return response()->json(['data' => ['id' => $signer->id, 'is_active' => false]]);
    }

    /** POST /crm/proposals/{p}/importar-caderno — inclui na proposta os participantes salvos do cliente. */
    public function importarCaderno(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        $n = app(\App\Services\ProposalParticipantService::class)->aplicarCaderno($crmProposal);
        return response()->json(['data' => ['incluidos' => $n]]);
    }

    /** GET /crm/signature-profile?email= — perfil de assinatura salvo (p/ pré-preencher / assinatura 1-clique). */
    public function signatureProfile(Request $request): JsonResponse
    {
        $perfil = \App\Models\CrmSignatureProfile::porEmail($request->query('email'));
        if (!$perfil) return response()->json(['data' => null]);
        return response()->json(['data' => [
            'email' => $perfil->email, 'name' => $perfil->name, 'cpf' => $perfil->cpf, 'cargo' => $perfil->cargo,
            'has_image' => filled($perfil->image), 'times_used' => $perfil->times_used,
        ]]);
    }

    /**
     * POST /crm/proposals/{p}/participantes/{part}/assinar — P-E.2.4: o usuário logado assina pela CONTRATADA
     * direto no editor (assinatura nativa). Dados vazios herdam o perfil salvo do e-mail (sem redigitar/desenhar).
     */
    public function assinarParticipante(Request $request, CrmProposal $crmProposal, \App\Models\CrmProposalParticipant $part): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        abort_unless($part->crm_proposal_id === $crmProposal->id, 404);
        if (!$part->hasRole('signer')) return response()->json(['message' => 'Este participante não é assinante.'], 422);
        if ($part->parte !== 'contratada') return response()->json(['message' => 'Pelo editor, o usuário logado assina apenas pela Contratada.'], 422);
        if ($part->signed_at) return response()->json(['data' => ['signed' => true]]);
        $v = $request->validate([
            'nome' => 'nullable|string|max:160', 'cpf' => 'nullable|string|max:20',
            'cargo' => 'nullable|string|max:120', 'imagem' => 'nullable|string',
        ]);
        // Garante status apto p/ a assinatura nativa (Contratada inicia a fase de assinatura).
        if (in_array($crmProposal->status, ['reprovada', 'cancelada', 'expirada', 'convertida', 'liberada'], true)) {
            return response()->json(['message' => 'Proposta não disponível para assinatura.'], 422);
        }
        if (!in_array($crmProposal->status, ['aprovada', 'aguardando_assinatura'], true)) {
            $crmProposal->update(['status' => 'aguardando_assinatura']);
        }
        try {
            app(\App\Services\ProposalParticipantService::class)->assinar($part, $request->ip(), $request->userAgent(), [
                'nome' => $v['nome'] ?? null, 'cpf' => $v['cpf'] ?? null, 'cargo' => $v['cargo'] ?? null,
                'imagem' => (isset($v['imagem']) && str_starts_with((string) $v['imagem'], 'data:image/')) ? mb_substr($v['imagem'], 0, 600000) : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => ['signed' => true, 'status' => $crmProposal->fresh()->status]]);
    }

    /** POST /crm/proposals/{p}/participantes/{part}/reenviar — reenvia o convite por e-mail. */
    public function reenviarConvite(Request $request, CrmProposal $crmProposal, \App\Models\CrmProposalParticipant $part): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        abort_unless($part->crm_proposal_id === $crmProposal->id, 404);
        try {
            $sent = app(\App\Services\ProposalParticipantService::class)->reenviar($part, $request->user()->id);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['data' => [
            'sent' => $sent, 'invite_count' => $part->fresh()->invite_count,
            'message' => $sent ? "Convite reenviado para {$part->email}." : 'Convite registrado, mas o e-mail não pôde ser enviado agora.',
        ]]);
    }

    /** DELETE /crm/proposals/{p}/participantes/{part} — desativa (não exclui histórico). */
    public function desativarParticipante(Request $request, CrmProposal $crmProposal, \App\Models\CrmProposalParticipant $part): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        abort_unless($part->crm_proposal_id === $crmProposal->id, 404);
        app(\App\Services\ProposalParticipantService::class)->desativar($part, $request->user()->id);
        return response()->json(['data' => ['id' => $part->id, 'is_active' => false]]);
    }

    // ───────────── P-C.2 — Revisões por seção (lado comercial / gestão) ─────────────

    /** GET /crm/proposals/{p}/threads — threads de revisão (todas as seções) p/ a gestão. */
    public function threads(CrmProposal $crmProposal): JsonResponse
    {
        $svc = app(\App\Services\ProposalReviewService::class);
        $titulos = $crmProposal->sections()->pluck('title', 'section_key');
        $list = \App\Models\CrmProposalReviewThread::with(['messages.participant:id,name', 'messages.authorUser:id,name', 'author:id,name'])
            ->where('crm_proposal_id', $crmProposal->id)->orderByDesc('id')->get()
            ->map(function ($t) use ($svc, $titulos) {
                $row = $svc->serializeThread($t);
                $row['section_title'] = $titulos[$t->section_key] ?? $t->section_key;
                return $row;
            });
        return response()->json(['data' => $list, 'summary' => $svc->summary($crmProposal), 'versao' => (int) ($crmProposal->versao ?: 1)]);
    }

    /** POST /crm/proposals/{p}/threads/{thread}/mensagens — comercial responde (autor interno). */
    public function comentarThread(Request $request, CrmProposal $crmProposal, \App\Models\CrmProposalReviewThread $thread): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        abort_unless($thread->crm_proposal_id === $crmProposal->id, 404);
        $v = $request->validate(['message' => 'required|string|max:4000']);
        app(\App\Services\ProposalReviewService::class)->mensagem($thread, null, $request->user(), $v['message']);
        return response()->json(['data' => app(\App\Services\ProposalReviewService::class)->serializeThread($thread->fresh(['messages.participant', 'messages.authorUser', 'author']))]);
    }

    /** POST /crm/proposals/{p}/threads/{thread}/resolver — comercial resolve a thread. */
    public function resolverThread(Request $request, CrmProposal $crmProposal, \App\Models\CrmProposalReviewThread $thread): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        abort_unless($thread->crm_proposal_id === $crmProposal->id, 404);
        app(\App\Services\ProposalReviewService::class)->resolver($thread, null, $request->user());
        return response()->json(['data' => ['status' => 'resolvida', 'proposta_status' => $crmProposal->fresh()->status]]);
    }

    /** POST /crm/proposals/{p}/enviar-assinatura — P-E.2.0: envia a proposta p/ assinatura via Clicksign. */
    public function enviarAssinatura(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        // P-E.2.3 — fluxo flexível: bloqueia só estados terminais/concluídos.
        if (in_array($crmProposal->status, ['assinada', 'liberada', 'convertida', 'reprovada', 'cancelada', 'expirada'], true)) {
            return response()->json(['message' => 'A proposta não está em condição de enviar para assinatura.'], 422);
        }
        // P-E.2.3 — quando HÁ aprovadores, exige aprovação concluída antes da assinatura. Sem aprovador = envio direto.
        $ativos = $crmProposal->participants()->where('is_active', true)->get();
        $approvers = $ativos->filter(fn ($x) => $x->hasRole('approver'));
        // Bloqueia com aprovação pendente, salvo override explícito do vendedor (forcar = pular aprovação).
        if (!$request->boolean('forcar') && $approvers->isNotEmpty() && $approvers->contains(fn ($x) => $x->approved_at === null)) {
            $pend = $approvers->filter(fn ($x) => !$x->approved_at)->pluck('name')->implode(', ');
            return response()->json(['message' => "Aprovação pendente ({$pend}) — conclua a aprovação antes de enviar para assinatura.", 'code' => 'APROVACAO_PENDENTE'], 422);
        }
        $signers = $ativos->filter(fn ($x) => $x->hasRole('signer'))->values();
        if ($signers->isEmpty()) return response()->json(['message' => 'Defina ao menos um assinante antes de enviar à assinatura.'], 422);
        // P-E.2.4 — fluxo NATIVO (padrão): assina no PORTAL do Minutor. Garante PDF + share p/ o link do portal.
        $sender = $request->user();
        try {
            $doc = app(CrmProposalService::class)->gerarDocumento($crmProposal, $sender, true);
            $this->shareParaEnvio($crmProposal, $doc->id, $sender->id, null);
        } catch (\Throwable $e) { \Log::warning('[assinatura] falha ao gerar doc/share: ' . $e->getMessage()); }
        $clienteName = $crmProposal->loadMissing('customer')->customer?->name ?? 'cliente';
        $assunto = trim((string) $request->input('assunto')) ?: $this->assinaturaSubject($crmProposal, $clienteName);
        $mensagem = trim((string) $request->input('mensagem')) ?: $this->assinaturaDefaultMensagem();
        $emailFalhas = $this->enviarEmailAssinatura($crmProposal, $signers, $sender, $assunto, $mensagem, $clienteName);
        $crmProposal->update(['status' => 'aguardando_assinatura']);
        if ($crmProposal->opportunity_id) {
            CrmOpportunityEvent::log((int) $crmProposal->opportunity_id, 'note', [
                'to_value' => "Proposta {$crmProposal->codigo} enviada para assinatura por {$sender->name} — " . $signers->count() . ' assinante(s) (portal Minutor)',
                'meta' => ['kind' => 'assinatura_enviada'],
            ]);
        }
        return response()->json(['data' => [
            'status' => 'aguardando_assinatura',
            'email_falhas' => $emailFalhas,
        ]]);
    }

    /** Envia, COMO o usuário logado, o e-mail de assinatura para cada signatário com seu LINK DO PORTAL (assinatura nativa). */
    private function enviarEmailAssinatura(CrmProposal $p, $signers, $sender, string $assunto, string $mensagem, string $clienteName): array
    {
        $falhas = [];
        foreach ($signers as $s) {
            if (!filled($s->email)) continue;
            $link = $this->portalParticipanteLink($p, $s) ?: '#';
            try {
                $html = view('emails.crm.proposta', [
                    'clienteName' => $s->name ?: $clienteName, 'senderName' => $sender->name,
                    'codigo' => $p->codigo, 'tipoLabel' => self::TIPO_LABELS[$p->tipo ?? 'bh_fixo'] ?? null,
                    'valorTotal' => $this->brlProposta((float) $p->total),
                    'validade' => optional($p->data_validade)->format('d/m/Y'),
                    'mensagem' => $mensagem, 'portalUrl' => $link, 'withAttachments' => false,
                    'ctaLabel' => 'Assinar a proposta', 'ctaCaption' => 'Você abre a proposta e assina diretamente no portal, com segurança.',
                ])->render();
                if (\App\Services\GraphMailer::enabled() && filled($sender->email)) {
                    \App\Services\GraphMailer::sendAs($sender->email, [$s->email], [], $assunto, $html, []);
                } else {
                    \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($s, $assunto, $sender) {
                        $m->to($s->email)->subject($assunto);
                        if (filled($sender->email)) $m->replyTo($sender->email, $sender->name);
                    });
                }
                $s->update(['invited_at' => $s->invited_at ?: now(), 'last_invite_at' => now(), 'invite_count' => (int) $s->invite_count + 1]);
            } catch (\Throwable $e) {
                $falhas[] = $s->email;
                \Log::error('[assinatura] falha ao enviar e-mail', ['proposta' => $p->id, 'signer' => $s->email, 'erro' => $e->getMessage()]);
            }
        }
        return $falhas;
    }

    /** GET /crm/proposals/{p}/assinatura — status do envelope + signatários (timeline + copiar link). */
    public function assinaturaStatus(CrmProposal $crmProposal): JsonResponse
    {
        $env = \App\Models\ClicksignEnvelope::with('signers')->where('crm_proposal_id', $crmProposal->id)->orderByDesc('id')->first();
        if (!$env) return response()->json(['data' => null]);
        return response()->json(['data' => [
            'envelope_status' => $env->status, 'is_active' => $env->is_active, 'enviado_em' => optional($env->sent_at)->toIso8601String(),
            'signatarios' => $env->signers->map(fn ($s) => [
                'nome' => $s->name, 'email' => $s->email, 'status' => $s->status,
                'assinado_em' => optional($s->signed_at)->toIso8601String(), 'sign_url' => $s->sign_url,
            ])->values(),
        ]]);
    }

    /** POST /crm/proposals/{p}/reenviar-assinatura — reenvia a notificação Clicksign (cliente perdeu e-mail/link). */
    public function reenviarAssinatura(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        // Clicksign (opcional) tem reenvio próprio; no fluxo nativo, reenvia o LINK DO PORTAL aos pendentes.
        $env = \App\Models\ClicksignEnvelope::where('crm_proposal_id', $crmProposal->id)->where('is_active', true)->orderByDesc('id')->first();
        if ($env) {
            try { app(\App\Services\Clicksign\ClicksignService::class)->reenviarNotificacao($env); }
            catch (\Throwable $e) { return response()->json(['message' => 'Falha ao reenviar: ' . $e->getMessage()], 422); }
            return response()->json(['data' => ['ok' => true]]);
        }
        $pend = $crmProposal->participants()->where('is_active', true)->get()
            ->filter(fn ($x) => $x->hasRole('signer') && !$x->signed_at)->values();
        if ($pend->isEmpty()) return response()->json(['message' => 'Nenhum assinante pendente.'], 422);
        $clienteName = $crmProposal->loadMissing('customer')->customer?->name ?? 'cliente';
        $falhas = $this->enviarEmailAssinatura($crmProposal, $pend, $request->user(), $this->assinaturaSubject($crmProposal, $clienteName), $this->assinaturaDefaultMensagem(), $clienteName);
        return response()->json(['data' => ['ok' => true, 'email_falhas' => $falhas, 'reenviados' => $pend->count()]]);
    }

    /**
     * POST /crm/proposals/{p}/sincronizar-assinatura — P-E.2.4: consulta o Clicksign e atualiza status/assinaturas
     * sem depender do webhook (útil em localhost). Se o envelope finalizou, dispara a captura do PDF assinado.
     */
    public function sincronizarAssinatura(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        $r = app(CrmProposalService::class)->sincronizarClicksign($crmProposal, $request->user());
        if (!($r['ok'] ?? false)) return response()->json(['message' => $r['erro'] ?? 'Nada a sincronizar.', 'code' => ($r['stub'] ?? false) ? 'STUB' : null], 422);
        return response()->json(['data' => ['status_proposta' => $r['status'], 'envelope_status' => $r['envelope_status'] ?? null, 'finalizou' => $r['finalizou'] ?? false]]);
    }

    /** POST /crm/proposals/{p}/cancelar-assinatura — cancela o envelope; libera nova versão (aguardando_assinatura → aprovada). */
    public function cancelarAssinatura(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        $env = \App\Models\ClicksignEnvelope::where('crm_proposal_id', $crmProposal->id)->where('is_active', true)->orderByDesc('id')->first();
        if ($env) app(\App\Services\Clicksign\ClicksignService::class)->cancelar($env);
        if ($crmProposal->status === 'aguardando_assinatura') $crmProposal->update(['status' => 'aprovada']);
        // limpa carimbos de assinatura dos participantes (assinatura cancelada)
        $crmProposal->participants()->whereNull('signed_at')->update(['sign_status' => 'cancelled', 'sign_status_at' => now()]);
        if ($crmProposal->opportunity_id) {
            CrmOpportunityEvent::log((int) $crmProposal->opportunity_id, 'note', [
                'to_value' => "Assinatura cancelada por {$request->user()->name} — proposta liberada para nova versão", 'meta' => ['kind' => 'assinatura_cancelada'],
            ]);
        }
        return response()->json(['data' => ['status' => $crmProposal->fresh()->status]]);
    }

    /** POST /crm/proposals/{p}/solicitar-assinatura — comercial solicita assinatura (aprovada → aguardando_assinatura). */
    public function solicitarAssinatura(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        if ($crmProposal->status !== 'aprovada') {
            return response()->json(['message' => 'A proposta precisa estar APROVADA para solicitar assinatura.'], 422);
        }
        $crmProposal->update(['status' => 'aguardando_assinatura']);
        if ($crmProposal->opportunity_id) {
            CrmOpportunityEvent::log((int) $crmProposal->opportunity_id, 'note', [
                'to_value' => "Proposta {$crmProposal->codigo}: assinatura solicitada ao cliente",
                'meta' => ['kind' => 'aguardando_assinatura'],
            ]);
        }
        return response()->json(['data' => ['status' => 'aguardando_assinatura']]);
    }

    /**
     * POST /crm/proposals/{p}/solicitar-aprovacao — P-E.2.3: solicita a aprovação aos aprovadores
     * com e-mail COMO o usuário logado (template editável + link individual do portal). Só com aprovadores.
     */
    public function solicitarAprovacao(Request $request, CrmProposal $crmProposal, CrmProposalService $svcDoc): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        $svc = app(\App\Services\ProposalParticipantService::class);
        $ativos = $crmProposal->participants()->where('is_active', true)->get();
        $approvers = $ativos->filter(fn ($x) => $x->hasRole('approver'));
        if ($approvers->isEmpty()) {
            return response()->json(['message' => 'Nenhum aprovador definido — esta proposta segue direto para assinatura.', 'code' => 'SEM_APROVADOR'], 422);
        }
        $pend = $approvers->filter(fn ($x) => !$x->approved_at);
        // Garante PDF + share p/ o link individual do portal funcionar no e-mail.
        if ($crmProposal->codigo && $crmProposal->calc_id) {
            try { $doc = $svcDoc->gerarDocumento($crmProposal, $request->user(), true); $this->shareParaEnvio($crmProposal, $doc->id, $request->user()->id, null); }
            catch (\Throwable $e) { \Log::warning('[aprovacao] falha ao gerar doc/share: ' . $e->getMessage()); }
        }
        $clienteName = $crmProposal->loadMissing('customer')->customer?->name ?? 'cliente';
        $assunto = trim((string) $request->input('assunto')) ?: $this->aprovacaoSubject($crmProposal, $clienteName);
        $mensagem = trim((string) $request->input('mensagem')) ?: $this->aprovacaoDefaultMensagem();
        $falhas = $this->enviarEmailAprovacao($crmProposal, $pend, $request->user(), $assunto, $mensagem, $clienteName);

        if (in_array($crmProposal->status, ['em_elaboracao', 'reativada'], true)) $crmProposal->update(['status' => 'enviada']);
        elseif ($crmProposal->status === 'enviada') $crmProposal->update(['status' => 'em_analise']);
        if ($crmProposal->opportunity_id) {
            CrmOpportunityEvent::log((int) $crmProposal->opportunity_id, 'note', [
                'to_value' => "Aprovação solicitada por {$request->user()->name} — {$pend->count()} aprovador(es) pendente(s)",
                'meta' => ['kind' => 'aprovacao_solicitada'],
            ]);
        }
        return response()->json(['data' => ['status' => $crmProposal->fresh()->status, 'pendentes' => $pend->pluck('name')->values(), 'email_falhas' => $falhas]]);
    }

    /** Envia, COMO o usuário logado, o e-mail de aprovação para cada aprovador pendente com seu link de portal. */
    private function enviarEmailAprovacao(CrmProposal $p, $aprovadores, $sender, string $assunto, string $mensagem, string $clienteName): array
    {
        $falhas = [];
        foreach ($aprovadores as $ap) {
            if (!filled($ap->email)) continue;
            $link = $this->portalParticipanteLink($p, $ap) ?: '#';
            try {
                $html = view('emails.crm.proposta', [
                    'clienteName' => $ap->name ?: $clienteName, 'senderName' => $sender->name,
                    'codigo' => $p->codigo, 'tipoLabel' => self::TIPO_LABELS[$p->tipo ?? 'bh_fixo'] ?? null,
                    'valorTotal' => $this->brlProposta((float) $p->total),
                    'validade' => optional($p->data_validade)->format('d/m/Y'),
                    'mensagem' => $mensagem, 'portalUrl' => $link, 'withAttachments' => false,
                    'ctaLabel' => 'Revisar e aprovar a proposta', 'ctaCaption' => 'Você acessa a proposta completa e registra sua aprovação no portal.',
                ])->render();
                if (\App\Services\GraphMailer::enabled() && filled($sender->email)) {
                    \App\Services\GraphMailer::sendAs($sender->email, [$ap->email], [], $assunto, $html, []);
                } else {
                    \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($ap, $assunto, $sender) {
                        $m->to($ap->email)->subject($assunto);
                        if (filled($sender->email)) $m->replyTo($sender->email, $sender->name);
                    });
                }
                $ap->update(['invited_at' => $ap->invited_at ?: now(), 'last_invite_at' => now(), 'invite_count' => (int) $ap->invite_count + 1]);
            } catch (\Throwable $e) {
                $falhas[] = $ap->email;
                \Log::error('[aprovacao] falha ao enviar e-mail', ['proposta' => $p->id, 'aprovador' => $ap->email, 'erro' => $e->getMessage()]);
            }
        }
        return $falhas;
    }

    /** POST /crm/proposals/{p}/bloquear — HOLD operacional reversível (não altera status). */
    public function bloquear(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        $v = $request->validate(['motivo' => 'required|string|max:500']);
        $crmProposal->update(['bloqueado_por' => $request->user()->id, 'bloqueado_em' => now(), 'motivo_bloqueio' => $v['motivo']]);
        return response()->json(['data' => ['bloqueado' => true]]);
    }

    /** POST /crm/proposals/{p}/desbloquear. */
    public function desbloquear(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        if (!$crmProposal->bloqueado_em) return response()->json(['message' => 'A proposta não está bloqueada.'], 422);
        $crmProposal->update(['bloqueado_por' => null, 'bloqueado_em' => null, 'motivo_bloqueio' => null]);
        return response()->json(['data' => ['bloqueado' => false]]);
    }

    /** POST /crm/proposals/{p}/marcar-perda — V1.5: encerra a proposta (recusada/perdida/expirada) com motivo. */
    public function marcarPerda(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        if ($crmProposal->status === 'convertida') return response()->json(['message' => 'Proposta já convertida não pode ser marcada como perdida.'], 422);
        $v = $request->validate([
            'status'         => 'required|in:reprovada,cancelada,expirada',
            'loss_reason_id' => 'required|exists:crm_loss_reasons,id',
            'observacao'     => 'nullable|string|max:1000',
        ]);
        $crmProposal->update([
            'status' => $v['status'], 'loss_reason_id' => $v['loss_reason_id'],
            'motivo_perda_obs' => $v['observacao'] ?? null, 'perdida_em' => now(), 'perdida_por' => $request->user()->id,
        ]);
        $motivo = \App\Models\CrmLossReason::find($v['loss_reason_id'])?->name;
        $rotulo = ['reprovada' => 'recusada', 'cancelada' => 'perdida', 'expirada' => 'expirada'][$v['status']];
        if ($crmProposal->opportunity_id) {
            CrmOpportunityEvent::log((int) $crmProposal->opportunity_id, 'note', [
                'to_value' => "Proposta {$crmProposal->codigo} {$rotulo} — motivo: {$motivo}" . (!empty($v['observacao']) ? " ({$v['observacao']})" : ''),
                'meta' => ['kind' => 'proposta_perdida', 'status' => $v['status'], 'loss_reason_id' => $v['loss_reason_id']],
            ]);
        }
        return response()->json(['data' => ['status' => $v['status'], 'motivo' => $motivo]]);
    }

    /** POST /crm/proposals/{p}/gerar-projeto — gera o Projeto DIRETO da proposta liberada (sem Contract). */
    public function gerarProjeto(Request $request, CrmProposal $crmProposal): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        if ($crmProposal->status !== 'liberado_execucao') {
            return response()->json(['message' => 'A proposta precisa estar "Liberada para Execução".'], 422);
        }
        if ($crmProposal->project_id) {
            return response()->json(['message' => 'Projeto já gerado.', 'project_id' => $crmProposal->project_id], 422);
        }
        $crmProposal->loadMissing(['calc', 'customer', 'opportunity']);
        $customer = $crmProposal->customer;
        if (!$customer) return response()->json(['message' => 'Proposta sem cliente vinculado.'], 422);

        $inputs   = (array) ($crmProposal->calc?->inputs ?? []);
        $outputs  = (array) ($crmProposal->calc?->outputs ?? []);
        $conteudo = (array) ($crmProposal->conteudo ?? []);
        $tipo     = $crmProposal->tipo ?: 'bh_fixo';
        $isFixo   = in_array($tipo, ['on_demand', 'projeto_fechado'], true);
        $horas    = (int) ($inputs['horas_consultoria'] ?? 0);
        $valorHora = (float) ($inputs['venda_h'] ?? $inputs['valor_hora_cliente'] ?? 0);
        $coordHoras = (int) ($outputs['coordenacao_horas'] ?? 0);
        $escopo   = trim((string) ($conteudo['escopo']['objetivo'] ?? $inputs['escopo_texto'] ?? ''));
        $pat = ['bh_fixo' => '%fixo%', 'bh_mensal' => '%mensal%', 'on_demand' => '%demand%', 'projeto_fechado' => '%fechado%', 'cloud' => '%cloud%'][$tipo] ?? null;
        $ctId = $pat ? \App\Models\ContractType::where('name', 'ilike', $pat)->value('id') : null;
        // projects.service_type_id é NOT NULL — herda do conteúdo da proposta ou cai num default (vendedor ajusta).
        $stId = $conteudo['service_type_id'] ?? ($conteudo['identificacao']['service_type_id'] ?? ($inputs['service_type_id'] ?? null));
        $stId = $stId ?: \App\Models\ServiceType::orderBy('id')->value('id');

        $project = \Illuminate\Support\Facades\DB::transaction(function () use ($crmProposal, $customer, $isFixo, $horas, $valorHora, $coordHoras, $escopo, $ctId, $stId) {
            $codeData = (new \App\Services\ProjectCodeService())->resolveForStore($crmProposal->codigo, $customer, null);
            $project = \App\Models\Project::create(array_merge($codeData, [
                'name'                 => $crmProposal->codigo . ' — ' . (optional($crmProposal->opportunity)->title ?: $customer->name),
                'customer_id'          => $customer->id,
                'service_type_id'      => $stId,
                'contract_type_id'     => $ctId,
                'sold_hours'           => $isFixo ? 0 : $horas,
                'consultant_hours'     => $isFixo ? null : ($horas ?: null),
                'coordination_hours'   => $coordHoras ?: null,
                'project_value'        => (float) $crmProposal->total,
                'hourly_rate'          => $valorHora ?: null,
                'status'               => \App\Models\Project::STATUS_AWAITING_START,
                'vendedor_id'          => $crmProposal->vendedor_id,
                'executivo_conta_id'   => $customer->executive_id,
                'observacoes_contrato' => $escopo ?: null,
                'contract_id'          => null, // PROPOSAL-CENTRIC: NÃO depende de Contract
            ]));
            $crmProposal->update(['project_id' => $project->id, 'status' => 'convertida']);
            if ($crmProposal->opportunity_id) {
                CrmOpportunityEvent::log((int) $crmProposal->opportunity_id, 'note', [
                    'to_value' => "Projeto {$project->code} gerado a partir da proposta {$crmProposal->codigo}",
                    'meta' => ['kind' => 'projeto_gerado', 'project_id' => $project->id],
                ]);
            }
            return $project;
        });

        return response()->json(['data' => ['project_id' => $project->id, 'code' => $project->code, 'status' => $crmProposal->fresh()->status]], 201);
    }

    /** GET /crm/proposals/analytics-conteudo — P-E.1.2 §4: inteligência de conteúdo agregada. */
    public function analyticsConteudo(Request $request): JsonResponse
    {
        if (!$this->podeGerir($request)) return response()->json(['message' => 'Sem permissão.'], 403);
        return response()->json(['data' => app(\App\Services\ProposalAnalyticsService::class)->conteudoAgregado()]);
    }

    /** GET /crm/proposals/board — gestão COMERCIAL das propostas (kanban por status + cards + paradas). */
    public function board(Request $request): JsonResponse
    {
        $q = CrmProposal::query()->whereNotNull('codigo')
            ->with(['customer:id,name', 'vendedor:id,name']);

        if ($request->filled('cliente'))  $q->where('customer_id', $request->integer('cliente'));
        if ($request->filled('vendedor')) $q->where('vendedor_id', $request->integer('vendedor'));
        if ($request->filled('status'))   $q->where('status', $request->input('status'));
        if ($request->boolean('bloqueadas'))  $q->whereNotNull('bloqueado_em');
        if ($request->filled('de'))   $q->whereDate('data_emissao', '>=', $request->input('de'));
        if ($request->filled('ate'))  $q->whereDate('data_emissao', '<=', $request->input('ate'));

        $now = now();
        $lista = $q->orderByDesc('updated_at')->limit(500)->get();
        // P-E.1 gestão: revisões pendentes por proposta (agregado único, sem N+1).
        $revPend = \App\Models\CrmProposalReviewThread::whereIn('crm_proposal_id', $lista->pluck('id'))
            ->whereIn('status', ['aberta', 'respondida'])
            ->selectRaw('crm_proposal_id, count(*) as total')->groupBy('crm_proposal_id')
            ->pluck('total', 'crm_proposal_id');
        // P-E.1.2 — scores de engajamento/prontidão p/ os badges do Kanban (batch).
        $scores = app(\App\Services\ProposalAnalyticsService::class)->scoresBatch($lista);
        $propostas = $lista->map(function ($p) use ($now, $revPend, $scores) {
            $dias = $p->updated_at ? (int) $p->updated_at->diffInDays($now) : 0;
            $cicloIni = $p->data_emissao ?: $p->created_at;
            return [
                'id' => $p->id, 'codigo' => $p->codigo, 'status' => $p->status,
                'cliente' => $p->customer?->name, 'customer_id' => $p->customer_id,
                'valor' => (float) $p->total, 'vendedor' => $p->vendedor?->name, 'vendedor_id' => $p->vendedor_id,
                'atualizado_em' => optional($p->updated_at)->toIso8601String(), 'dias_parada' => $dias,
                'versao' => $p->versao,
                'revisoes_pendentes' => (int) ($revPend[$p->id] ?? 0),
                'ciclo_dias' => $cicloIni ? (int) \Carbon\Carbon::parse($cicloIni)->diffInDays($now) : null,
                'engajamento' => $scores[$p->id]['engajamento']['score'] ?? 0,
                'prontidao'   => $scores[$p->id]['prontidao'] ?? null,
                'bloqueada' => $p->bloqueado_em !== null,
            ];
        });

        // Cards comerciais (globais, todas as propostas com código).
        $base = fn () => CrmProposal::whereNotNull('codigo');
        $cards = [
            'em_revisao'  => $base()->where('status', 'em_revisao')->count(),
            'assinada'    => $base()->where('status', 'assinada')->count(),
            'liberada'    => $base()->where('status', 'liberada')->count(),
            'convertida'  => $base()->where('status', 'convertida')->count(),
        ];

        // Propostas paradas (sem movimentação) — exclui terminais (convertida/reprovada/cancelada/expirada).
        $abertas = $base()->whereNotIn('status', ['convertida', 'reprovada', 'cancelada', 'expirada'])->get(['updated_at']);
        $paradas = ['d7' => 0, 'd15' => 0, 'd30' => 0];
        foreach ($abertas as $p) {
            $d = $p->updated_at ? (int) $p->updated_at->diffInDays($now) : 0;
            if ($d > 30) $paradas['d30']++;
            elseif ($d > 15) $paradas['d15']++;
            elseif ($d > 7) $paradas['d7']++;
        }

        return response()->json(['data' => ['propostas' => $propostas, 'cards' => $cards, 'paradas' => $paradas]]);
    }
}
