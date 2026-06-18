<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentService;
use App\Documents\CrmProposalService;
use App\Models\Attachment;
use App\Models\CrmProposal;
use App\Models\CrmProposalCalc;
use App\Models\CrmOpportunityEvent;
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
        // Fluxo NOVO (com tipo + memória de cálculo): delega ao motor único — gera o calc congelado,
        // reserva o código (sequenciador dos projetos), seta status oficial e o tipo do template.
        if ($request->filled('tipo')) {
            $request->validate([
                'opportunity_id'   => 'required|exists:crm_opportunities,id',
                'tipo'             => 'required|in:bh_fixo,bh_mensal,on_demand,projeto_fechado',
                'modo_faturamento' => 'nullable|in:por_hora,valor_fixo',
                'inputs'           => 'nullable|array',
                'data_validade'    => 'nullable|date',
            ]);
            // Descrição da Oportunidade é obrigatória ANTES de gerar a proposta.
            $opp = \App\Models\CrmOpportunity::find($request->opportunity_id);
            if ($opp && !filled($opp->descricao)) {
                return response()->json(['message' => 'Preencha a "Descrição da Oportunidade" antes de criar a proposta.'], 422);
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
            'tipo'     => 'required|in:bh_fixo,bh_mensal,on_demand,projeto_fechado',
            'inputs'   => 'nullable|array',
            'conteudo' => 'nullable|array',
            'modo_faturamento' => 'nullable|in:por_hora,valor_fixo',
        ]);
        $data = $svc->previewData([
            'tipo' => $request->tipo,
            'inputs' => $request->input('inputs', []),
            'conteudo' => $request->input('conteudo', []),
            'modo_faturamento' => $request->input('modo_faturamento'),
            'codigo' => $request->input('codigo'),
            'versao' => $request->input('versao', 1),
            'customer_id' => $request->input('customer_id'),
            'vendedor_id' => $request->input('vendedor_id'),
            'data_emissao' => $request->input('data_emissao'),
        ]);
        return response()->json(['data' => $data]);
    }

    /** Salva edição in-place (recalcula memória de cálculo; não gera nova versão). */
    public function editar(Request $request, CrmProposal $crmProposal, CrmProposalService $svc): JsonResponse
    {
        $request->validate([
            'inputs'   => 'nullable|array',
            'conteudo' => 'nullable|array',
            'data_validade' => 'nullable|date',
            'tipo' => 'nullable|in:bh_fixo,bh_mensal,on_demand,projeto_fechado',
            'modo_faturamento' => 'nullable|in:por_hora,valor_fixo',
        ]);
        $p = $svc->editar($crmProposal, $request->only(['inputs', 'conteudo', 'data_validade', 'tipo', 'modo_faturamento']), $request->user());
        return response()->json(['data' => array_merge($p->toArray(), ['total' => $p->total])]);
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
        $isSlide = preg_match('#^slides/(bh_fixo|bh_mensal|on_demand|projeto_fechado)/slide-\d{2}\.png$#', $path);
        $isFont  = preg_match('#^fonts/(RobotoCondensed|BebasNeue)\.ttf$#', $path);
        abort_unless($isSlide || $isFont, 404);
        $file = resource_path('assets/propostas/' . $path);
        abort_unless(is_file($file), 404);
        $ct = $isFont ? 'font/ttf' : 'image/png';
        return response()->file($file, ['Content-Type' => $ct, 'Cache-Control' => 'private, max-age=86400']);
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
}
