<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectStageController extends Controller
{
    public function index(Project $project, Request $request): JsonResponse
    {
        $user = $request->user();
        $isConsultor = $user && method_exists($user, 'isConsultor') && $user->isConsultor();

        $stages = $project->stages()
            ->when($isConsultor, function ($q) use ($user) {
                // Consultor só vê etapas onde tem alocação OU é responsável por entrega (ADR 0004)
                $q->where(function ($w) use ($user) {
                    $w->whereHas('allocations', fn ($a) => $a->where('user_id', $user->id))
                      ->orWhereHas('deliveries', fn ($d) => $d->where('responsible_user_id', $user->id));
                });
            })
            ->withCount([
                'deliveries',
                'deliveries as deliveries_done_count' => function ($q) {
                    $q->where('status', \App\Models\StageDelivery::STATUS_DONE);
                },
                'deliveries as deliveries_backlog_count' => function ($q) {
                    $q->where('status', \App\Models\StageDelivery::STATUS_BACKLOG);
                },
                'deliveries as deliveries_in_progress_count' => function ($q) {
                    $q->where('status', \App\Models\StageDelivery::STATUS_IN_PROGRESS);
                },
                'deliveries as deliveries_waiting_client_count' => function ($q) {
                    $q->where('status', \App\Models\StageDelivery::STATUS_WAITING_CLIENT);
                },
                'deliveries as deliveries_review_count' => function ($q) {
                    $q->where('status', \App\Models\StageDelivery::STATUS_REVIEW);
                },
            ])
            ->withSum('deliveries as deliveries_hours_planned_sum', 'hours_planned')
            ->withSum(['deliveries as deliveries_hours_planned_done_sum' => function ($q) {
                $q->where('status', \App\Models\StageDelivery::STATUS_DONE);
            }], 'hours_planned')
            ->withMax('activityEvents as last_activity_at', 'created_at')
            ->withSum('aportes as aportes_hours_sum', 'hours')
            ->orderBy('order_index')
            ->get();

        // Pré-busca agregados por etapa em 1 query (evita N+1)
        $stageIds = $stages->pluck('id')->all();
        $overrunByStage  = $this->computeTeamOverrunByStage($stageIds);
        $consumedByStage = $this->computeConsumedHoursByStage($stageIds);

        $stages->each(function ($s) use ($overrunByStage, $consumedByStage) {
            // Earned value (ADR 0002)
            $totalHours = (float) ($s->deliveries_hours_planned_sum ?? 0);
            $doneHours  = (float) ($s->deliveries_hours_planned_done_sum ?? 0);

            // Fix Fase 9 (UX): horas efetivas = hours_planned se > 0, senão soma das deliveries.
            // Evita que UI mostre "0h planejadas" quando as horas estão no nível atividade.
            $stagePlanned = (float) ($s->hours_planned ?? 0);
            $s->effective_hours_planned = $stagePlanned > 0 ? $stagePlanned : $totalHours;
            if ($totalHours > 0) {
                $s->progress_pct = round(($doneHours / $totalHours) * 100, 2);
            } elseif (($s->deliveries_count ?? 0) > 0) {
                $s->progress_pct = round((($s->deliveries_done_count ?? 0) / $s->deliveries_count) * 100, 2);
            } else {
                $s->progress_pct = 0.0;
            }

            // Status macro derivado das entregas. Regra: etapa SÓ avança quando
            // 100% das entregas atingem o estado seguinte. Bloqueada é override
            // imediato quando ≥1 entrega está aguardando cliente.
            $total       = (int) ($s->deliveries_count ?? 0);
            $done        = (int) ($s->deliveries_done_count ?? 0);
            $review      = (int) ($s->deliveries_review_count ?? 0);
            $waiting     = (int) ($s->deliveries_waiting_client_count ?? 0);
            $backlog     = (int) ($s->deliveries_backlog_count ?? 0);

            if ($total === 0) {
                $s->derived_status = 'planejamento';
            } elseif ($waiting > 0) {
                // Override: qualquer entrega aguardando cliente trava a etapa
                $s->derived_status = 'bloqueada';
            } elseif ($done === $total) {
                $s->derived_status = 'concluida';
            } elseif (($review + $done) === $total) {
                // 100% das entregas em homologação ou já concluídas
                $s->derived_status = 'homologacao';
            } elseif ($backlog === 0) {
                // 100% das entregas saíram de backlog (alguma em in_progress, review, done)
                $s->derived_status = 'execucao';
            } else {
                // Alguma entrega ainda em backlog
                $s->derived_status = 'planejamento';
            }

            // 4º dot: equipe (red se ≥1 alocação estourada).
            $s->team_overrun_count = (int) ($overrunByStage[$s->id] ?? 0);

            // Dias desde a última atividade (timeline). Null se nunca houve atividade.
            $s->days_since_activity = $s->last_activity_at
                ? \Carbon\Carbon::parse($s->last_activity_at)->diffInDays(now())
                : null;

            // Consumo real de horas da etapa (timesheets approved + released)
            $s->actual_hours = (float) ($consumedByStage[$s->id] ?? 0);

            // Risco operacional derivado (ADR 0006)
            $risk = \App\Services\ProjectStageRiskService::compute([
                'derived_status'       => $s->derived_status,
                'expected_end_date'    => $s->expected_end_date?->toDateString(),
                'days_since_activity'  => $s->days_since_activity,
                'planned_hours'        => (float) $s->hours_planned,
                'actual_hours'         => $s->actual_hours,
                'team_overrun_count'   => $s->team_overrun_count,
            ]);
            $s->risk_level   = $risk['level'];
            $s->risk_reasons = $risk['reasons'];
        });

        return response()->json(['items' => $stages]);
    }

    /**
     * Soma de horas consumidas por etapa (timesheets approved + released).
     * Retorna map [stage_id => actual_hours].
     */
    private function computeConsumedHoursByStage(array $stageIds): array
    {
        if (empty($stageIds)) return [];

        $rows = DB::table('timesheets')
            ->whereIn('stage_id', $stageIds)
            ->whereNull('deleted_at')
            ->whereIn('status', [\App\Models\Timesheet::STATUS_APPROVED, \App\Models\Timesheet::STATUS_RELEASED])
            ->groupBy('stage_id')
            ->selectRaw('stage_id, COALESCE(SUM(effort_minutes), 0) / 60.0 AS actual_hours')
            ->get();

        return $rows->pluck('actual_hours', 'stage_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v])
            ->toArray();
    }

    /**
     * Conta quantas alocações têm actual > planned por etapa.
     * Conta apenas timesheets approved + released (consistente com ADR 0003).
     * Retorna map [stage_id => overrun_count].
     */
    private function computeTeamOverrunByStage(array $stageIds): array
    {
        if (empty($stageIds)) return [];

        // Subquery: actual por (stage_id, user_id) — apenas approved/released, não soft-deletado
        $tsSum = DB::table('timesheets')
            ->whereIn('stage_id', $stageIds)
            ->whereNull('deleted_at')
            ->whereIn('status', [\App\Models\Timesheet::STATUS_APPROVED, \App\Models\Timesheet::STATUS_RELEASED])
            ->groupBy('user_id', 'stage_id')
            ->selectRaw('user_id, stage_id, COALESCE(SUM(effort_minutes), 0) / 60.0 AS actual_hours');

        // Join allocations com a soma, filtra estouradas, agrupa por etapa
        $rows = DB::table('stage_allocations as a')
            ->leftJoinSub($tsSum, 'ts', function ($join) {
                $join->on('ts.user_id', '=', 'a.user_id')
                    ->on('ts.stage_id', '=', 'a.stage_id');
            })
            ->whereIn('a.stage_id', $stageIds)
            ->whereRaw('COALESCE(ts.actual_hours, 0) > a.planned_hours')
            ->groupBy('a.stage_id')
            ->selectRaw('a.stage_id, COUNT(*) AS overrun_count')
            ->get();

        return $rows->pluck('overrun_count', 'stage_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v])
            ->toArray();
    }

    public function show(ProjectStage $stage): JsonResponse
    {
        $stage->load(['deliveries']);

        return response()->json($stage);
    }

    /**
     * Timeline operacional da etapa (append-only). Ver ADR 0005.
     * Eventos mais recentes primeiro. Paginação simples via ?limit (default 50, max 200).
     */
    public function activity(ProjectStage $stage, Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 50), 200);

        $events = $stage->activityEvents()
            ->with('actor:id,name,email')
            ->limit($limit)
            ->get();

        return response()->json(['items' => $events]);
    }

    /**
     * Comentário operacional na etapa (Pilar 3 — chat contextual).
     *
     * @deprecated 2026-05-15 — chat stage-level virou legado. ADR 0007 moveu
     * comunicação pra atividade. Use POST /activities/{delivery}/comments
     * (StageDeliveryController::storeComment). Comentários antigos ficam
     * visíveis na timeline agregada da etapa (delivery_id=null).
     *
     * Cria evento append-only `type=comment` em `stage_activity_events`.
     * Aceita texto livre, anexo (file 20MB max) e mentions `@[id:Name]`.
     * Mantém ADR 0005 — sem tabela paralela.
     */
    public function storeComment(Request $request, ProjectStage $stage): JsonResponse
    {
        \Log::warning('[deprecated] POST /stages/{id}/comments — use POST /activities/{id}/comments (ADR 0007)', [
            'stage_id' => $stage->id,
            'user_id'  => $request->user()?->id,
            'route'    => $request->path(),
        ]);

        $user = $request->user();

        // Filtro de escrita: consultor só comenta em etapa que participa
        if ($user && method_exists($user, 'isConsultor') && $user->isConsultor()) {
            $allowed = $stage->allocations()->where('user_id', $user->id)->exists()
                || $stage->deliveries()->where('responsible_user_id', $user->id)->exists();
            if (!$allowed) {
                return response()->json([
                    'message' => 'Você não está alocado nesta etapa.',
                ], 403);
            }
        }

        $data = $request->validate([
            'text'                 => 'nullable|string|max:5000',
            'attachment'           => 'nullable|file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip',
            'mentioned_user_ids'   => 'nullable|array',
            'mentioned_user_ids.*' => 'integer|exists:users,id',
        ]);

        $text       = trim((string) ($data['text'] ?? ''));
        $hasAttach  = $request->hasFile('attachment');
        $mentioned  = array_map('intval', $data['mentioned_user_ids'] ?? []);

        if ($text === '' && !$hasAttach) {
            return response()->json([
                'message' => 'Comentário precisa de texto ou anexo.',
            ], 422);
        }

        $attachmentData = [];
        if ($hasAttach) {
            $file = $request->file('attachment');
            $path = $file->store("stage-attachments/{$stage->id}", 'public');
            $attachmentData = [
                'attachment_path'          => $path,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime'          => $file->getMimeType(),
                'attachment_size'          => $file->getSize(),
            ];
        }

        $event = \App\Models\StageActivityEvent::create(array_merge([
            'stage_id'      => $stage->id,
            'actor_user_id' => $user?->id,
            'type'          => \App\Models\StageActivityEvent::TYPE_COMMENT,
            'payload'       => array_filter([
                'text'                => $text !== '' ? $text : null,
                'mentioned_user_ids'  => !empty($mentioned) ? $mentioned : null,
            ]),
        ], $attachmentData));

        return response()->json($event->load('actor:id,name,email'), 201);
    }

    /**
     * Risco de atraso do projeto (Pilar 2).
     *
     * Compara o prazo macro do projeto (`projects.expected_end_date`) com o
     * fim previsto da última etapa (`max(project_stages.expected_end_date)`).
     * Retorna delay_days > 0 quando alguma etapa termina depois do prazo macro.
     *
     * NÃO deriva prazo do projeto pelas etapas — apenas expõe o diff visual.
     * Etapas concluídas (status=done) saem do cálculo (não pesam mais).
     */
    public function delayRisk(Project $project): JsonResponse
    {
        $projectEnd = $project->expected_end_date
            ? \Carbon\Carbon::parse($project->expected_end_date)->startOfDay()
            : null;

        $latestStage = $project->stages()
            ->whereNotNull('expected_end_date')
            ->where('status', '!=', ProjectStage::STATUS_DONE)
            ->max('expected_end_date');

        // Considera também due_date das atividades não-concluídas — coord pode
        // ter atividade indo além do fim da etapa-pai. Sem isso, sugestão de
        // prazo no header subestima.
        $latestDelivery = \App\Models\StageDelivery::whereHas('stage', fn ($q) =>
                $q->where('project_id', $project->id))
            ->whereNotNull('due_date')
            ->where('status', '!=', \App\Models\StageDelivery::STATUS_DONE)
            ->max('due_date');

        $latestStageEnd = null;
        foreach ([$latestStage, $latestDelivery] as $d) {
            if (!$d) continue;
            $c = \Carbon\Carbon::parse($d)->startOfDay();
            if (!$latestStageEnd || $c->gt($latestStageEnd)) $latestStageEnd = $c;
        }

        $delayDays = ($projectEnd && $latestStageEnd && $latestStageEnd->gt($projectEnd))
            ? (int) $projectEnd->diffInDays($latestStageEnd)
            : 0;

        return response()->json([
            'project_end'      => $projectEnd?->toDateString(),
            'latest_stage_end' => $latestStageEnd?->toDateString(),
            'delay_days'       => $delayDays,
            'has_risk'         => $delayDays > 0,
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'name'                => 'required|string|max:100',
            'hours_planned'       => 'nullable|numeric|min:0',
            'status'              => ['nullable', Rule::in(ProjectStage::STATUSES)],
            'expected_end_date'   => 'nullable|date',
            'stage_start_at'      => 'nullable|date',
            'parent_stage_id'     => 'nullable|integer|exists:project_stages,id',
        ]);

        // Fim não pode ser anterior ao início.
        if (!empty($data['stage_start_at']) && !empty($data['expected_end_date'])
            && substr((string) $data['expected_end_date'], 0, 10) < substr((string) $data['stage_start_at'], 0, 10)) {
            return response()->json(['message' => 'A data de fim não pode ser anterior ao início.'], 422);
        }

        // Sub-etapa: valida que a etapa-mãe é do mesmo projeto e é de TOPO (máx. 2
        // níveis de etapa → não permite sub-sub-etapa).
        if (!empty($data['parent_stage_id'])) {
            $parent = ProjectStage::find($data['parent_stage_id']);
            if (!$parent || $parent->project_id !== $project->id) {
                return response()->json(['message' => 'Etapa-mãe inválida.'], 422);
            }
            if ($parent->parent_stage_id !== null) {
                return response()->json(['message' => 'Sub-etapa não pode ter sub-etapa (máximo 2 níveis).'], 422);
            }
        }

        // Bloqueia se a nova etapa fizer o uso do pool passar do liberado à gestão.
        $project->loadMissing('serviceType');
        if ($project->isOperational() && isset($data['hours_planned'])) {
            $err = $this->guardProjectCapacity($project, (float) $data['hours_planned'], null);
            if ($err !== null) return $err;
        }

        $data['project_id'] = $project->id;
        // order_index dentro do mesmo nível (entre as sub-etapas da mãe, ou entre as de topo).
        $data['order_index'] = (int) $project->stages()
            ->where('parent_stage_id', $data['parent_stage_id'] ?? null)
            ->max('order_index') + 1;

        $stage = ProjectStage::create($data);

        // Prazo de entrega do projeto deriva sempre da última data do cronograma.
        $project->recalcExpectedEndFromSchedule();
        $project->recomputeStatusFromStages(); // nova etapa → Em Planejamento (board)

        return response()->json($stage, 201);
    }

    public function update(Request $request, ProjectStage $stage): JsonResponse
    {
        $data = $request->validate([
            'name'                => 'sometimes|string|max:100',
            'hours_planned'       => 'sometimes|numeric|min:0',
            'status'              => ['sometimes', Rule::in(ProjectStage::STATUSES)],
            'blocked_reason'      => 'nullable|string|max:500',
            'expected_end_date'   => 'nullable|date',
            'stage_start_at'      => 'nullable|date',
        ]);

        // Fim não pode ser anterior ao início (datas efetivas após a edição).
        $effStart = array_key_exists('stage_start_at', $data) ? $data['stage_start_at'] : optional($stage->stage_start_at)->toDateString();
        $effEnd   = array_key_exists('expected_end_date', $data) ? $data['expected_end_date'] : optional($stage->expected_end_date)->toDateString();
        if (!empty($effStart) && !empty($effEnd)
            && substr((string) $effEnd, 0, 10) < substr((string) $effStart, 0, 10)) {
            return response()->json(['message' => 'A data de fim não pode ser anterior ao início.'], 422);
        }

        // Se está definindo hours_planned em projeto operacional, valida o pool.
        $stage->loadMissing('project.serviceType');
        if ($stage->project?->isOperational() && isset($data['hours_planned'])) {
            $err = $this->guardProjectCapacity($stage->project, (float) $data['hours_planned'], $stage);
            if ($err !== null) return $err;
        }

        // Detecta transição de bloqueio pra registrar timeline (ADR 0005)
        $previousReason = $stage->blocked_reason;

        $stage->update($data);

        if (array_key_exists('blocked_reason', $data)) {
            $newReason = trim((string) ($data['blocked_reason'] ?? ''));
            $oldReason = trim((string) ($previousReason ?? ''));

            if ($newReason !== $oldReason) {
                $type = $newReason !== ''
                    ? \App\Models\StageActivityEvent::TYPE_BLOCK_SET
                    : \App\Models\StageActivityEvent::TYPE_BLOCK_CLEARED;

                \App\Models\StageActivityEvent::create([
                    'stage_id'      => $stage->id,
                    'actor_user_id' => $request->user()?->id,
                    'type'          => $type,
                    'payload'       => array_filter([
                        'reason'      => $newReason !== '' ? $newReason : null,
                        'prev_reason' => $oldReason !== '' ? $oldReason : null,
                    ]),
                ]);
            }
        }

        // Prazo de entrega do projeto deriva sempre da última data do cronograma.
        $stage->project?->recalcExpectedEndFromSchedule();
        $stage->project?->recomputeStatusFromStages();

        return response()->json($stage->fresh());
    }

    /**
     * Bloqueia se o planejamento da etapa fizer o USO DO POOL passar do liberado à
     * gestão. Usa horas EFETIVAS (Project::plannedPoolUsage): etapa com horas próprias
     * conta as dela; etapa em rollup conta a soma das atividades não-cliente.
     * Pool = coordination_hours (ou 100% das vendidas se não preenchido).
     * NÃO é afrouxado por allow_negative_balance (planejamento sempre dentro do pool).
     *
     * @param float $newStageHours novo hours_planned da etapa.
     * @param ProjectStage|null $editingStage etapa existente em edição (null = nova).
     */
    private function guardProjectCapacity(Project $project, float $newStageHours, ?ProjectStage $editingStage = null): ?JsonResponse
    {
        $pool = $project->cronogramaPoolHours();
        $used = $project->plannedPoolUsage($editingStage?->id);
        // Contribuição efetiva da etapa: horas próprias se >0; senão suas atividades.
        $effectiveNew = $newStageHours > 0
            ? $newStageHours
            : ($editingStage
                ? (float) \App\Models\StageDelivery::where('stage_id', $editingStage->id)
                    ->where('client_involved', false)->sum('hours_planned')
                : 0.0);

        if ($used + $effectiveNew > $pool + 0.001) {
            $available = max(0.0, $pool - $used);
            return response()->json([
                'message' => 'Sem saldo no cronograma. O planejamento não pode passar das horas liberadas à gestão.',
                'detail'  => sprintf(
                    'Tentativa de planejar %.1fh nesta etapa. Liberado à gestão: %.1fh · já planejado (outras etapas): %.1fh · disponível: %.1fh.',
                    $effectiveNew, $pool, $used, $available
                ),
            ], 422);
        }
        return null;
    }

    public function destroy(ProjectStage $stage): JsonResponse
    {
        $project = $stage->project;
        $stage->delete();

        // Remover a última etapa pode antecipar o prazo — recalcula.
        $project?->recalcExpectedEndFromSchedule();
        $project?->recomputeStatusFromStages();

        return response()->json(['deleted' => true]);
    }

    /**
     * Reordena etapas de um projeto. Payload: { stage_ids: [3, 1, 2] }
     */
    public function reorder(Request $request, Project $project): JsonResponse
    {

        $data = $request->validate([
            'stage_ids'   => 'required|array|min:1',
            'stage_ids.*' => 'integer|exists:project_stages,id',
        ]);

        DB::transaction(function () use ($data, $project) {
            foreach ($data['stage_ids'] as $index => $id) {
                ProjectStage::where('id', $id)
                    ->where('project_id', $project->id)
                    ->update(['order_index' => $index]);
            }
        });

        return response()->json(['reordered' => true]);
    }
}
