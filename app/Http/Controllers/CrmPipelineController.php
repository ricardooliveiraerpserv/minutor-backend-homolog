<?php

namespace App\Http\Controllers;

use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\CrmPipelineEvent;
use App\Models\CrmStageAutomation;
use App\Models\CrmOpportunity;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** CRM — funis e etapas CONFIGURÁVEIS (Fase 1). Seed só inicializa; gestão via CRUD. */
class CrmPipelineController extends Controller
{
    /** Funis padrão (seed idempotente). */
    private const SEED = [
        ['code' => 'novo_cliente', 'name' => 'Novo Cliente', 'ordem' => 1,
         'stages' => ['Lead', 'Qualificação', 'Diagnóstico', 'Proposta', 'Negociação', 'Ganho', 'Perdido']],
        ['code' => 'renovacao', 'name' => 'Renovação', 'ordem' => 2,
         'stages' => ['Renovação Aberta', 'Negociação', 'Aprovado', 'Perdido']],
        ['code' => 'expansao', 'name' => 'Expansão', 'ordem' => 3,
         'stages' => ['Identificação', 'Diagnóstico', 'Proposta', 'Negociação', 'Ganho', 'Perdido']],
    ];

    /** Funil de QUALIFICAÇÃO de leads — separado dos funis comerciais. */
    private const QUALIFICATION_SEED = [
        'code' => 'qualificacao', 'name' => 'Qualificação de Leads', 'ordem' => 0,
        'stages' => ['Novo Lead', 'Contato Inicial', 'Qualificação', 'Prospect', 'Perdido'],
    ];

    public static function ensureSeeded(): void
    {
        if (!CrmPipeline::where('code', '!=', 'qualificacao')->exists()) {
            foreach (self::SEED as $p) {
                $pipe = CrmPipeline::create(['code' => $p['code'], 'name' => $p['name'], 'ordem' => $p['ordem'], 'tipo' => 'comercial']);
                $n = count($p['stages']);
                foreach ($p['stages'] as $i => $name) {
                    $won = in_array($name, ['Ganho', 'Aprovado'], true);
                    $lost = $name === 'Perdido';
                    CrmPipelineStage::create([
                        'pipeline_id'   => $pipe->id,
                        'name'          => $name,
                        'ordem'         => $i + 1,
                        'is_won'        => $won,
                        'is_lost'       => $lost,
                        'is_inicial'    => $i === 0,
                        'probabilidade' => $won ? 100 : ($lost ? 0 : (int) round(($i + 1) / max($n, 1) * 80)),
                        'regras'        => mb_strtolower($name) === 'proposta' ? ['produto'] : null,
                    ]);
                }
            }
        }
        self::ensureQualificationSeeded();
    }

    /** Seed idempotente do funil de qualificação (independente dos funis comerciais já criados). */
    public static function ensureQualificationSeeded(): CrmPipeline
    {
        $p = self::QUALIFICATION_SEED;
        $pipe = CrmPipeline::firstOrCreate(['code' => $p['code']], ['name' => $p['name'], 'ordem' => $p['ordem'], 'tipo' => 'qualificacao']);
        if (!$pipe->stages()->exists()) {
            foreach ($p['stages'] as $i => $name) {
                CrmPipelineStage::create([
                    'pipeline_id' => $pipe->id,
                    'name'        => $name,
                    'ordem'       => $i + 1,
                    'is_won'      => $name === 'Prospect',
                    'is_lost'     => $name === 'Perdido',
                    'is_inicial'  => $i === 0,
                ]);
            }
        }
        return $pipe;
    }

    /** Pipeline de QUALIFICAÇÃO (tipo=qualificacao) — usado pela tela de Leads. */
    public static function qualificationPipeline(): CrmPipeline
    {
        return CrmPipeline::where('tipo', 'qualificacao')->first() ?? self::ensureQualificationSeeded();
    }

    /** Funis COMERCIAIS ativos (tipo=comercial) — usados no kanban de oportunidades. */
    public function index(): JsonResponse
    {
        self::ensureSeeded();
        return response()->json(['data' => CrmPipeline::with(['stages' => fn ($q) => $q->where('ativa', true)])
            ->where('active', true)->where('arquivado', false)->where('tipo', 'comercial')
            ->orderBy('ordem')->get()]);
    }

    // ── GESTÃO (Fase 1 — config sem desenvolvimento) ─────────────────────────
    /** Item 6 (Fase 5) — config de pipeline restrita a admin/administrativo (crm.pipeline.manage). */
    private function authorizeConfig(): void
    {
        $perms = auth()->check() ? PermissionService::for(auth()->user()) : [];
        $ok = in_array('*', $perms, true) || in_array('crm.pipeline.manage', $perms, true);
        abort_unless($ok, 403, 'Sem permissão para configurar pipelines do CRM.');
    }

    /** Todos os pipelines (comercial + qualificação) com etapas, p/ a tela de cadastro. */
    public function manageIndex(): JsonResponse
    {
        $this->authorizeConfig();
        self::ensureSeeded();
        return response()->json(['data' => CrmPipeline::with('stages')->orderBy('ordem')->get()
            ->map(fn ($p) => $this->decoratePipeline($p))]);
    }

    private function decoratePipeline(CrmPipeline $p): array
    {
        return array_merge($p->toArray(), [
            'stages' => $p->stages->map(fn ($s) => array_merge($s->toArray(), [
                'oportunidades_count' => CrmOpportunity::where('stage_id', $s->id)->count(),
            ]))->values(),
        ]);
    }

    public function storePipeline(Request $request): JsonResponse
    {
        $this->authorizeConfig();
        $v = $request->validate([
            'name' => 'required|string|max:80', 'descricao' => 'nullable|string|max:200',
            'cor' => 'nullable|string|max:16', 'active' => 'boolean',
        ]);
        $v['ordem'] = (int) CrmPipeline::max('ordem') + 1;
        $v['tipo'] = 'comercial';
        $v['code'] = 'pipe_' . \Illuminate\Support\Str::random(8);
        $pipe = CrmPipeline::create($v);
        CrmPipelineEvent::log('pipeline_criado', $pipe->id, null, "Pipeline \"{$pipe->name}\" criado", null, $pipe->only(['name', 'descricao', 'cor']));
        return response()->json(['data' => $this->decoratePipeline($pipe->load('stages'))], 201);
    }

    public function updatePipeline(Request $request, CrmPipeline $pipeline): JsonResponse
    {
        $this->authorizeConfig();
        $v = $request->validate([
            'name' => 'sometimes|string|max:80', 'descricao' => 'nullable|string|max:200',
            'cor' => 'nullable|string|max:16', 'active' => 'boolean', 'bloqueado' => 'boolean', 'arquivado' => 'boolean',
        ]);
        $antes = $pipeline->only(array_keys($v));
        $pipeline->update($v);
        $acao = array_key_exists('arquivado', $v) && $v['arquivado'] ? 'pipeline_arquivado' : 'pipeline_alterado';
        CrmPipelineEvent::log($acao, $pipeline->id, null, "Pipeline \"{$pipeline->name}\"", $antes, $v);
        return response()->json(['data' => $this->decoratePipeline($pipeline->fresh('stages'))]);
    }

    public function reorderPipelines(Request $request): JsonResponse
    {
        $this->authorizeConfig();
        $v = $request->validate(['ordem' => 'required|array', 'ordem.*' => 'integer|exists:crm_pipelines,id']);
        foreach ($v['ordem'] as $i => $id) CrmPipeline::where('id', $id)->update(['ordem' => $i + 1]);
        return response()->json(['ok' => true]);
    }

    /** Item 3 (Fase 5) — duplica pipeline + etapas + regras + automações (sem oportunidades/histórico). */
    public function duplicatePipeline(CrmPipeline $pipeline): JsonResponse
    {
        $this->authorizeConfig();
        $novo = DB::transaction(function () use ($pipeline) {
            $novo = CrmPipeline::create([
                'name' => 'Cópia de ' . $pipeline->name, 'descricao' => $pipeline->descricao,
                'cor' => $pipeline->cor, 'tipo' => 'comercial', 'active' => true, 'bloqueado' => false, 'arquivado' => false,
                'ordem' => (int) CrmPipeline::max('ordem') + 1, 'code' => 'pipe_' . \Illuminate\Support\Str::random(8),
            ]);
            foreach ($pipeline->stages()->orderBy('ordem')->get() as $s) {
                $ns = CrmPipelineStage::create([
                    'pipeline_id' => $novo->id, 'name' => $s->name, 'ordem' => $s->ordem,
                    'is_won' => $s->is_won, 'is_lost' => $s->is_lost, 'is_inicial' => $s->is_inicial, 'ativa' => $s->ativa,
                    'probabilidade' => $s->probabilidade, 'sla_dias' => $s->sla_dias, 'cor' => $s->cor, 'regras' => $s->regras,
                ]);
                foreach ($s->automations as $a) {
                    CrmStageAutomation::create(['stage_id' => $ns->id, 'evento' => $a->evento, 'tipo' => $a->tipo, 'config' => $a->config, 'ordem' => $a->ordem, 'ativa' => $a->ativa]);
                }
            }
            return $novo;
        });
        CrmPipelineEvent::log('pipeline_duplicado', $novo->id, null, "Duplicado de \"{$pipeline->name}\"");
        return response()->json(['data' => $this->decoratePipeline($novo->load('stages'))], 201);
    }

    public function storeStage(Request $request, CrmPipeline $pipeline): JsonResponse
    {
        $this->authorizeConfig();
        $v = $this->stageRules($request);
        $v['pipeline_id'] = $pipeline->id;
        $v['ordem'] = (int) CrmPipelineStage::where('pipeline_id', $pipeline->id)->max('ordem') + 1;
        $stage = CrmPipelineStage::create($v);
        $this->normalizeInicial($pipeline, $stage);
        CrmPipelineEvent::log('etapa_criada', $pipeline->id, $stage->id, "Etapa \"{$stage->name}\" criada", null, $stage->only(['name', 'probabilidade', 'sla_dias']));
        return response()->json(['data' => $stage->fresh()], 201);
    }

    public function updateStage(Request $request, CrmPipelineStage $stage): JsonResponse
    {
        $this->authorizeConfig();
        $pipe = $stage->pipeline;
        if ($pipe->bloqueado && $request->filled('name') && $request->input('name') !== $stage->name) {
            return response()->json(['message' => 'Pipeline bloqueado: renomear etapas não é permitido.', 'code' => 'PIPELINE_BLOQUEADO'], 422);
        }
        $v = $this->stageRules($request, false);
        $antes = $stage->only(array_keys($v));
        $stage->update($v);
        $this->normalizeInicial($pipe, $stage->fresh());
        $acao = (array_key_exists('ativa', $v) && !$v['ativa']) ? 'etapa_inativada' : 'etapa_alterada';
        CrmPipelineEvent::log($acao, $pipe->id, $stage->id, "Etapa \"{$stage->name}\"", $antes, $v);
        return response()->json(['data' => $stage->fresh()]);
    }

    public function reorderStages(Request $request, CrmPipeline $pipeline): JsonResponse
    {
        $this->authorizeConfig();
        if ($pipeline->bloqueado) {
            return response()->json(['message' => 'Pipeline bloqueado: alterar a ordem das etapas não é permitido.', 'code' => 'PIPELINE_BLOQUEADO'], 422);
        }
        $v = $request->validate(['ordem' => 'required|array', 'ordem.*' => 'integer|exists:crm_pipeline_stages,id']);
        foreach ($v['ordem'] as $i => $id) {
            CrmPipelineStage::where('id', $id)->where('pipeline_id', $pipeline->id)->update(['ordem' => $i + 1]);
        }
        CrmPipelineEvent::log('etapa_reordenada', $pipeline->id, null, 'Etapas reordenadas', null, ['ordem' => $v['ordem']]);
        return response()->json(['ok' => true]);
    }

    public function destroyStage(CrmPipelineStage $stage): JsonResponse
    {
        $this->authorizeConfig();
        $pipe = $stage->pipeline;
        if ($pipe->bloqueado) {
            return response()->json(['message' => 'Pipeline bloqueado: excluir etapas não é permitido.', 'code' => 'PIPELINE_BLOQUEADO'], 422);
        }
        // Item 5 (Fase 5): só exclui se NÃO houver oportunidade/histórico vinculado (qualquer status).
        $vinc = CrmOpportunity::withTrashed()->where('stage_id', $stage->id)->count();
        if ($vinc > 0) {
            return response()->json([
                'message' => 'Esta etapa possui oportunidades ou histórico vinculados. Utilize a opção Inativar.',
                'code' => 'ETAPA_COM_OPORTUNIDADES', 'oportunidades' => $vinc,
            ], 422);
        }
        CrmPipelineEvent::log('etapa_removida', $pipe->id, $stage->id, "Etapa \"{$stage->name}\" excluída");
        $stage->delete();
        return response()->json(['ok' => true]);
    }

    /** Item 4 (Fase 5) — trilha de auditoria de configuração. */
    public function events(Request $request): JsonResponse
    {
        $this->authorizeConfig();
        $q = CrmPipelineEvent::with('user:id,name')
            ->when($request->filled('pipeline_id'), fn ($x) => $x->where('pipeline_id', $request->pipeline_id))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn ($e) => [
                'acao' => $e->acao, 'descricao' => $e->descricao, 'usuario' => $e->user?->name,
                'antes' => $e->antes, 'depois' => $e->depois, 'when' => $e->created_at,
                'pipeline_id' => $e->pipeline_id, 'stage_id' => $e->stage_id,
            ]);
        return response()->json(['data' => $q]);
    }

    private function stageRules(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name'          => ($creating ? 'required' : 'sometimes') . '|string|max:80',
            'cor'           => 'nullable|string|max:16',
            'probabilidade' => 'nullable|integer|min:0|max:100',
            'sla_dias'      => 'nullable|integer|min:0',
            'is_won'        => 'boolean', 'is_lost' => 'boolean',
            'is_inicial'    => 'boolean', 'ativa' => 'boolean',
            'regras'        => 'nullable|array',
            'regras.*'      => 'string|in:' . implode(',', CrmPipelineStage::REGRAS_DISPONIVEIS),
        ]);
    }

    /** Garante etapa inicial única por pipeline. */
    private function normalizeInicial(CrmPipeline $pipeline, CrmPipelineStage $stage): void
    {
        if ($stage->is_inicial) {
            CrmPipelineStage::where('pipeline_id', $pipeline->id)->where('id', '!=', $stage->id)
                ->where('is_inicial', true)->update(['is_inicial' => false]);
        }
    }
}
