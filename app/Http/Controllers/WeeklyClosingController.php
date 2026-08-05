<?php

namespace App\Http\Controllers;

use App\Models\ClosingLog;
use App\Models\WeekOpenPeriod;
use App\Services\ClosingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fechamento SEMANAL: status das semanas, reabertura (global/projeto) e log de encerramentos.
 * Semana = segunda→domingo; prazo = 2º dia útil da semana seguinte, 23:59 SP.
 */
class WeeklyClosingController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && ($u->isAdmin() || $u->isAdministrativo() || $u->isCoordenador()), 403, 'Acesso negado.');
    }

    /** Últimas semanas com status + reaberturas por projeto ativas. */
    public function index(Request $request): JsonResponse
    {
        $this->gate($request);
        $svc       = app(ClosingService::class);
        $now       = Carbon::now(self::TZ);
        $activated = $svc->weeklyActivatedAt();
        $monday    = $svc->weekStart($now->toDateString());

        $weeks = [];
        for ($i = 0; $i < 10; $i++) {
            $ws       = $monday->copy()->subWeeks($i);
            $deadline = $svc->weekDeadline($ws);
            $global   = WeekOpenPeriod::whereNull('project_id')
                ->where('week_start', $ws->toDateString())->whereNull('closed_at')
                ->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now()))->first();

            if ($now->lte($deadline) || $deadline->lt($activated)) $status = 'aberta';
            elseif ($global)                                       $status = 'reaberta';
            else                                                   $status = 'fechada';

            $weeks[] = [
                'week_start'           => $ws->toDateString(),
                'week_end'             => $ws->copy()->addDays(6)->toDateString(),
                'deadline'             => $deadline->toIso8601String(),
                'status'               => $status,
                'reopen_auto_close_at' => optional($global?->auto_close_at)->toIso8601String(),
            ];
        }

        $projReopens = WeekOpenPeriod::whereNotNull('project_id')->whereNull('closed_at')
            ->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now()))
            ->with('project:id,name')->orderByDesc('week_start')->limit(100)->get()
            ->map(fn ($p) => [
                'week_start'    => Carbon::parse($p->week_start)->toDateString(),
                'project_id'    => $p->project_id,
                'project'       => $p->project?->name,
                'auto_close_at' => optional($p->auto_close_at)->toIso8601String(),
            ]);

        return response()->json(['data' => $weeks, 'project_reopens' => $projReopens]);
    }

    /** Reabre a semana (global se sem project_id; senão só do projeto) até 23:59 de hoje. */
    public function reopen(Request $request): JsonResponse
    {
        $this->gate($request);
        $v = $request->validate([
            'week_start' => 'required|date',
            'project_id' => 'nullable|integer|exists:projects,id',
        ]);
        $svc       = app(ClosingService::class);
        $weekStart = $svc->weekStart($v['week_start']);
        $period    = $svc->reopenWeek($weekStart, $v['project_id'] ?? null, $request->user());

        return response()->json(['message' => 'Semana reaberta até 23:59 de hoje.', 'data' => $period], 201);
    }

    /** Fecha manualmente uma reabertura semanal antes das 23:59. */
    public function close(Request $request): JsonResponse
    {
        $this->gate($request);
        $v = $request->validate([
            'week_start' => 'required|date',
            'project_id' => 'nullable|integer',
        ]);
        $svc       = app(ClosingService::class);
        $weekStart = $svc->weekStart($v['week_start'])->toDateString();

        $q = WeekOpenPeriod::where('week_start', $weekStart)->whereNull('closed_at');
        $q = array_key_exists('project_id', $v) && $v['project_id'] !== null
            ? $q->where('project_id', $v['project_id'])
            : $q->whereNull('project_id');
        $n = $q->update(['closed_at' => now(), 'closed_by' => $request->user()->id]);

        return response()->json(['message' => "Reabertura fechada ({$n}).", 'count' => $n]);
    }

    /** Log de encerramentos (semanal/mensal). */
    public function logs(Request $request): JsonResponse
    {
        $this->gate($request);
        $rows = ClosingLog::with(['project:id,name', 'user:id,name'])
            ->where('event', '!=', 'activation')
            ->orderByDesc('occurred_at')->limit(300)->get()
            ->map(fn ($l) => [
                'id'          => $l->id,
                'event'       => $l->event,
                'period_kind' => $l->period_kind,
                'period_key'  => $l->period_key,
                'project'     => $l->project?->name,
                'user'        => $l->user?->name,
                'occurred_at' => optional($l->occurred_at)->toIso8601String(),
                'note'        => $l->note,
            ]);
        return response()->json(['data' => $rows]);
    }
}
