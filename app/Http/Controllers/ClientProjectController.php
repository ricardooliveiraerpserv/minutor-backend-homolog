<?php

namespace App\Http\Controllers;

use App\Models\FollowUp;
use App\Models\Project;
use App\Models\StageDelivery;
use App\Services\BusinessCalendarService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visão do CLIENTE sobre o projeto. Tudo em DIAS — nunca horas nem valores.
 *
 * Regras (definidas com o usuário):
 * - Cliente só acessa projetos onde está envolvido (client_involved) ou é
 *   responsável por alguma atividade.
 * - Vê o cronograma inteiro (estrutura), mas só ABRE os cards onde está
 *   envolvido / que dependem da aprovação dele / que ele é responsável.
 * - Conversa e anexos: só se for o RESPONSÁVEL do card (tratado no
 *   ClientActivityController).
 */
class ClientProjectController extends Controller
{
    /** Card que o cliente pode abrir: envolvido (aprovação) OU responsável. */
    public static function canOpen(StageDelivery $d, int $userId): bool
    {
        return ((bool) $d->client_involved && (int) $d->client_user_id === $userId)
            || (int) $d->responsible_user_id === $userId;
    }

    /** Resumo do projeto em dias (chamado pelo ProjectController@show p/ cliente). */
    public function summary(Project $project, $user): JsonResponse
    {
        if (($err = $this->ensureProjectAccess($project, $user)) !== null) return $err;

        $project->loadMissing('customer:id,name');

        // Progresso por atividades (done / total) nas etapas operacionais.
        $deliveries = StageDelivery::whereHas('stage', fn ($q) => $q->where('project_id', $project->id))->get(['id', 'status']);
        $total = $deliveries->count();
        $done  = $deliveries->where('status', StageDelivery::STATUS_DONE)->count();
        $progressPct = $total > 0 ? (int) round($done / $total * 100) : 0;

        $daysRemaining = null;
        if ($project->expected_end_date) {
            $calendar = app(BusinessCalendarService::class);
            $opts = ['allow_weekend' => (bool) $project->allow_weekend_work, 'allow_holiday' => (bool) $project->allow_holiday_work];
            $today = Carbon::now()->startOfDay();
            $end = Carbon::parse($project->expected_end_date)->startOfDay();
            $daysRemaining = $end->lt($today) ? 0 : $calendar->businessDaysBetween($today, $end, $opts);
        }

        return response()->json([
            'id'                => $project->id,
            'name'              => $project->name,
            'code'              => $project->code,
            'status'            => $project->status,
            'status_display'    => $project->status_display,
            'customer'          => $project->customer ? ['id' => $project->customer->id, 'name' => $project->customer->name] : null,
            'expected_end_date' => $project->expected_end_date,
            'is_operational'    => $project->isOperational(),
            'progress_pct'      => $progressPct,
            'total_activities'  => $total,
            'done_activities'   => $done,
            'days_remaining'    => $daysRemaining,
            'client_view'       => true,
        ]);
    }

    /** Cronograma em dias: etapas/sub-etapas + atividades (sem horas). */
    public function schedule(Project $project, Request $request): JsonResponse
    {
        $user = $request->user();
        // Cronograma (só status, sem horas/valores): cliente PARTICIPANTE do projeto OU cliente do MESMO
        // customer do projeto — mesma visibilidade do resumo operacional do portal (ClientPortalController).
        if (!$user || !$user->isCliente()) {
            return response()->json(['message' => 'Endpoint exclusivo do perfil cliente.'], 403);
        }
        if ((int) $user->customer_id !== (int) $project->customer_id
            && ! self::participatesInProject($project, (int) $user->id)) {
            return response()->json(['message' => 'Você não participa deste projeto.'], 403);
        }

        if (!$project->isOperational()) {
            return response()->json(['is_operational' => false, 'stages' => []]);
        }

        $calendar = app(BusinessCalendarService::class);
        $opts = ['allow_weekend' => (bool) $project->allow_weekend_work, 'allow_holiday' => (bool) $project->allow_holiday_work];

        $stages = $project->stages()
            ->with([
                'deliveries:id,stage_id,title,description,status,due_date,planned_start_at,completed_at,responsible_user_id,client_involved,client_user_id,approval_status,order_index,depends_on_delivery_id',
                'deliveries.responsible:id,name',
            ])
            ->orderBy('order_index')
            ->get(['id', 'parent_stage_id', 'name', 'order_index', 'project_id']);

        // Mapa id→título p/ a coluna "Depende de" (read-only).
        $titleById = [];
        foreach ($stages as $st) {
            foreach ($st->deliveries as $d) $titleById[$d->id] = $d->title;
        }

        $uid = (int) $user->id;

        $stagesOut = $stages->map(function ($st) use ($calendar, $opts, $uid, $titleById) {
            $dels = $st->deliveries->sortBy('order_index')->values()->map(function ($d) use ($calendar, $opts, $uid, $titleById) {
                // O requisitante já passou por ensureProjectAccess → é participante.
                $amSpecific  = (bool) $d->client_involved && (int) $d->client_user_id === $uid;
                $hasSpecific = (bool) $d->client_involved && $d->client_user_id !== null;
                $awaiting = $d->approval_status === StageDelivery::APPROVAL_PENDING
                    && $d->status === StageDelivery::STATUS_WAITING_CLIENT
                    && ($amSpecific || !$hasSpecific);
                $canOpen = $amSpecific || (int) $d->responsible_user_id === $uid || $awaiting;
                $duration = ($d->planned_start_at && $d->due_date)
                    ? $calendar->businessDaysBetween($d->planned_start_at, $d->due_date, $opts)
                    : null;
                $corridos = ($d->planned_start_at && $d->due_date)
                    ? $calendar->calendarDaysBetween($d->planned_start_at, $d->due_date)
                    : null;
                $naoUteis = ($d->planned_start_at && $d->due_date)
                    ? $calendar->nonBusinessDaysBetween($d->planned_start_at, $d->due_date, $opts)
                    : 0;
                return [
                    'id'                     => $d->id,
                    'title'                  => $d->title,
                    'status'                 => $d->status,
                    'planned_start_at'       => $d->planned_start_at?->toDateString(),
                    'due_date'               => $d->due_date?->toDateString(),
                    'completed_at'           => $d->completed_at?->toDateString(),
                    'duration_business_days' => $duration,
                    'duration_calendar_days' => $corridos,
                    'non_business_days'      => $naoUteis,
                    'responsible_name'       => $d->responsible?->name,
                    'depends_on_title'       => $d->depends_on_delivery_id ? ($titleById[$d->depends_on_delivery_id] ?? null) : null,
                    'can_open'               => $canOpen,
                    'is_responsible'         => (int) $d->responsible_user_id === $uid,
                    'awaiting_my_approval'   => $awaiting,
                    'approval_status'        => $d->approval_status,
                ];
            });

            $starts = $dels->pluck('planned_start_at')->filter()->values();
            $ends   = $dels->pluck('due_date')->filter()->values();
            $total  = $dels->count();
            $done   = $dels->where('status', StageDelivery::STATUS_DONE)->count();
            $stStart = $starts->min();
            $stEnd   = $ends->max();

            return [
                'id'              => $st->id,
                'parent_stage_id' => $st->parent_stage_id,
                'name'            => $st->name,
                'order_index'     => $st->order_index,
                'start'           => $stStart,
                'end'             => $stEnd,
                'duration_business_days' => ($stStart && $stEnd) ? $calendar->businessDaysBetween(Carbon::parse($stStart), Carbon::parse($stEnd), $opts) : null,
                'duration_calendar_days' => ($stStart && $stEnd) ? $calendar->calendarDaysBetween(Carbon::parse($stStart), Carbon::parse($stEnd)) : null,
                'non_business_days'      => ($stStart && $stEnd) ? $calendar->nonBusinessDaysBetween(Carbon::parse($stStart), Carbon::parse($stEnd), $opts) : 0,
                'progress_pct'    => $total > 0 ? (int) round($done / $total * 100) : 0,
                'deliveries'      => $dels,
            ];
        });

        return response()->json([
            'is_operational' => true,
            'stages'         => $stagesOut,
        ]);
    }

    /** Follow Ups do projeto onde o cliente está envolvido. */
    public function followUps(Project $project, Request $request): JsonResponse
    {
        $user = $request->user();
        if (($err = $this->ensureProjectAccess($project, $user)) !== null) return $err;

        $items = FollowUp::where('project_id', $project->id)
            ->where('status', '!=', FollowUp::STATUS_CANCELLED)
            ->where('client_involved', true)
            ->where('client_user_id', $user->id)
            ->with(['responsible:id,name', 'delivery:id,title', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'description', 'status', 'due_date', 'responsible_user_id', 'stage_id', 'delivery_id', 'created_by', 'created_at'])
            ->map(fn ($f) => [
                'id'             => $f->id,
                'title'          => $f->title,
                'description'    => $f->description,
                'status'         => $f->status,
                'due_date'       => $f->due_date?->toDateString(),
                'responsible'    => $f->responsible?->name,
                'author'         => $f->createdBy?->name,
                'delivery_id'    => $f->delivery_id,
                'delivery_title' => $f->delivery?->title,
                'created_at'     => $f->created_at?->toIso8601String(),
            ]);

        return response()->json(['items' => $items]);
    }

    /**
     * Cliente só acessa o projeto se tiver ao menos 1 atividade onde está
     * envolvido (aprovação) ou é responsável.
     */
    /** O cliente participa do projeto: viewer global OU envolvido/responsável em ≥1 atividade. */
    public static function participatesInProject(Project $project, int $uid): bool
    {
        if ($project->clientViewers()->where('users.id', $uid)->exists()) return true;
        return StageDelivery::whereHas('stage', fn ($q) => $q->where('project_id', $project->id))
            ->where(function ($q) use ($uid) {
                $q->where(function ($w) use ($uid) {
                    $w->where('client_involved', true)->where('client_user_id', $uid);
                })->orWhere('responsible_user_id', $uid);
            })
            ->exists();
    }

    /**
     * O cliente pode APROVAR este card? Sim quando está "aguardando cliente" com
     * aprovação pendente e ele é o cliente específico OU (não há cliente específico
     * e ele participa do projeto — aprovação geral do cliente do projeto).
     */
    public static function canApprove(StageDelivery $delivery, int $uid, Project $project): bool
    {
        if ($delivery->approval_status !== StageDelivery::APPROVAL_PENDING
            || $delivery->status !== StageDelivery::STATUS_WAITING_CLIENT) {
            return false;
        }
        $amSpecific  = (bool) $delivery->client_involved && (int) $delivery->client_user_id === $uid;
        $hasSpecific = (bool) $delivery->client_involved && $delivery->client_user_id !== null;
        return $amSpecific || (!$hasSpecific && self::participatesInProject($project, $uid));
    }

    private function ensureProjectAccess(Project $project, $user): ?JsonResponse
    {
        if (!$user || !$user->isCliente()) {
            return response()->json(['message' => 'Endpoint exclusivo do perfil cliente.'], 403);
        }
        if (!self::participatesInProject($project, (int) $user->id)) {
            return response()->json(['message' => 'Você não participa deste projeto.'], 403);
        }
        return null;
    }
}
