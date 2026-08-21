<?php

namespace App\Http\Controllers;

use App\Models\ClosingLog;
use App\Models\ProjectOpenPeriod;
use App\Models\WeekOpenPeriod;
use App\Services\ClosingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Painel de abertura de competência: semanas agrupadas por mês, status, reabrir/encerrar
 * (global / projeto / usuário), semana e mês, e log de encerramentos.
 */
class WeeklyClosingController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    private function gate(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && ($u->isAdmin() || $u->isAdministrativo() || $u->isCoordenador()), 403, 'Acesso negado.');
    }

    /** Meses (com semanas numeradas) + reaberturas ativas por escopo. */
    public function index(Request $request): JsonResponse
    {
        $this->gate($request);
        $svc = app(ClosingService::class);
        $now = Carbon::now(self::TZ);

        $months = [];
        for ($mi = 0; $mi < 4; $mi++) {
            $mDate = $now->copy()->startOfMonth()->subMonths($mi);
            $ym    = $mDate->format('Y-m');

            // 1ª semana cuja SEGUNDA cai neste mês.
            $ws = $mDate->copy()->startOfWeek(Carbon::MONDAY);
            if ($ws->format('Y-m') !== $ym) $ws->addWeek();

            $weeks = [];
            $n = 1;
            while ($ws->format('Y-m') === $ym) {
                $st = $svc->weekStatusGlobal($ws);
                $weeks[] = [
                    'n'                    => $n,
                    'week_start'           => $ws->toDateString(),
                    'week_end'             => $ws->copy()->addDays(6)->toDateString(),
                    'deadline'             => $st['deadline'],
                    'status'               => $st['status'],
                    'reopen_auto_close_at' => $st['auto_close_at'],
                ];
                $ws->addWeek();
                $n++;
            }

            $mst = $svc->monthStatusGlobal($ym);
            $months[] = [
                'ym'                   => $ym,
                'label'                => ucfirst($mDate->locale('pt_BR')->isoFormat('MMMM [de] YYYY')),
                'status'               => $mst['status'],
                'deadline'             => $mst['deadline'],
                'reopen_auto_close_at' => $mst['auto_close_at'],
                'weeks'                => $weeks,
            ];
        }

        // Reaberturas ATIVAS com escopo (projeto e/ou usuário) — para exibir/gerenciar.
        $active = collect();
        WeekOpenPeriod::whereNull('closed_at')->where(fn ($q) => $q->whereNotNull('project_id')->orWhereNotNull('user_id'))
            ->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now()))
            ->with(['project:id,name', 'openedBy:id,name'])->orderByDesc('week_start')->limit(100)->get()
            ->each(fn ($p) => $active->push([
                'period_kind' => 'week', 'period_key' => Carbon::parse($p->week_start)->toDateString(),
                'project_id' => $p->project_id, 'project' => $p->project?->name,
                'user_id' => $p->user_id, 'user' => $p->openedBy?->name,
                'auto_close_at' => optional($p->auto_close_at)->toIso8601String(),
            ]));
        ProjectOpenPeriod::whereNull('closed_at')->where(fn ($q) => $q->whereNotNull('project_id')->orWhereNotNull('user_id'))
            ->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now()))
            ->with(['project:id,name', 'openedBy:id,name'])->orderByDesc('year_month')->limit(100)->get()
            ->each(fn ($p) => $active->push([
                'period_kind' => 'month', 'period_key' => $p->year_month,
                'project_id' => $p->project_id, 'project' => $p->project?->name,
                'user_id' => $p->user_id, 'user' => $p->openedBy?->name,
                'auto_close_at' => optional($p->auto_close_at)->toIso8601String(),
            ]));

        // Encerramentos ESCOPADOS (por usuário e/ou projeto) — usuários/projetos bloqueados
        // INDIVIDUALMENTE, que NÃO aparecem no status global do mês/semana. Era o que travava
        // um consultor de forma invisível (ex.: mês fechado só p/ ele). Descontar os que já
        // têm reabertura ativa (não bloqueiam mais). Janela = os meses exibidos no painel.
        $minYm = $now->copy()->startOfMonth()->subMonths(3)->format('Y-m');
        $rows = \App\Models\CompetenceClosure::query()
            ->where(fn ($q) => $q->whereNotNull('project_id')->orWhereNotNull('user_id'))
            ->where('period_key', '>=', $minYm)
            ->with('project:id,name')
            ->orderBy('period_key')->limit(500)->get()
            ->filter(function ($c) use ($svc) {
                // Ainda bloqueia? (reabertura ativa do próprio escopo, ou do mês, libera)
                return $c->period_kind === 'week'
                    ? $svc->isWeekClosed($c->period_key, (int) ($c->project_id ?: 0), $c->user_id)
                    : $svc->isMonthClosed($c->period_key . '-15', (int) ($c->project_id ?: 0), $c->user_id);
            });
        $uids = $rows->pluck('user_id')->merge($rows->pluck('closed_by'))->filter()->unique()->values();
        $names = $uids->isEmpty() ? collect() : \App\Models\User::whereIn('id', $uids)->pluck('name', 'id');
        $scopedClosures = $rows->map(fn ($c) => [
            'id'             => $c->id,
            'period_kind'    => $c->period_kind,
            'period_key'     => $c->period_key,
            'project_id'     => $c->project_id,
            'project'        => $c->project?->name,
            'user_id'        => $c->user_id,
            'user'           => $c->user_id ? ($names[$c->user_id] ?? ('#' . $c->user_id)) : null,
            'closed_by'      => $c->closed_by,
            'closed_by_name' => $c->closed_by ? ($names[$c->closed_by] ?? null) : null,
            'closed_at'      => optional($c->closed_at ?: $c->created_at)->toIso8601String(),
        ])->values();

        return response()->json([
            'months'          => $months,
            'active_reopens'  => $active->values(),
            'scoped_closures' => $scopedClosures,
        ]);
    }

    private function validateScope(Request $request): array
    {
        return $request->validate([
            'period_kind' => 'required|in:week,month',
            'period_key'  => 'required|string|max:20',
            'project_id'  => 'nullable|integer|exists:projects,id',
            'user_id'     => 'nullable|integer|exists:users,id',
        ]);
    }

    public function reopen(Request $request): JsonResponse
    {
        $this->gate($request);
        $v   = $this->validateScope($request);
        $svc = app(ClosingService::class);
        if ($v['period_kind'] === 'week') {
            $svc->reopenWeek($svc->weekStart($v['period_key']), $v['project_id'] ?? null, $v['user_id'] ?? null, $request->user());
        } else {
            $svc->reopenMonth($v['period_key'], $v['project_id'] ?? null, $v['user_id'] ?? null, $request->user());
        }
        return response()->json(['message' => 'Período reaberto até as 23:59 de hoje.'], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $this->gate($request);
        $v   = $this->validateScope($request);
        $svc = app(ClosingService::class);
        if ($v['period_kind'] === 'week') {
            $svc->closeWeek($svc->weekStart($v['period_key']), $v['project_id'] ?? null, $v['user_id'] ?? null, $request->user());
        } else {
            $svc->closeMonth($v['period_key'], $v['project_id'] ?? null, $v['user_id'] ?? null, $request->user());
        }
        return response()->json(['message' => 'Período encerrado.']);
    }

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
