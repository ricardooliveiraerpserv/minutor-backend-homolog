<?php

namespace App\Http\Controllers;

use App\Models\ActionReminderRule;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Rotina (admin) de RECORRÊNCIA dos lembretes de ações não resolvidas. Define, por tipo de ação,
 * se re-lembra e a cada quantas horas/dias. O comando actions:remind-pending usa estas regras.
 */
class ActionReminderController extends Controller
{
    /** Lista as regras (cria as que faltam a partir do catálogo). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $this->ensureSeeded();

        $workflows = (array) config('workflows.workflows', []);
        $rules = ActionReminderRule::orderBy('id')->get()->map(function (ActionReminderRule $r) use ($workflows) {
            $d = ActionReminderRule::DEFAULTS[$r->key] ?? [];
            $wf = $d['workflow'] ?? null;
            return [
                'key'            => $r->key,
                'label'          => $d['label'] ?? $r->key,
                'audience'       => $d['audience'] ?? '',
                'enabled'        => $r->enabled,
                'unit'           => $r->unit,
                'interval'       => $r->interval,
                'last_fired_at'  => $r->last_fired_at?->toIso8601String(),
                'workflow'       => $wf,
                'workflow_label' => $wf ? ($workflows[$wf]['label'] ?? $wf) : null,
            ];
        });

        return response()->json(['data' => $rules]);
    }

    /** Atualiza uma regra (liga/desliga + unidade + intervalo). */
    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless(array_key_exists($key, ActionReminderRule::DEFAULTS), 404, 'Ação desconhecida.');

        $v = $request->validate([
            'enabled'  => 'required|boolean',
            'unit'     => 'required|in:hours,days',
            'interval' => 'required|integer|min:1|max:744',
        ]);

        $rule = ActionReminderRule::firstOrCreate(['key' => $key]);
        $rule->fill($v)->save();

        return response()->json(['data' => array_merge(['key' => $key], $v)]);
    }

    /** Prévia do e-mail do lembrete + quantos receberiam agora (mesma visão da Central de Notificações). */
    public function preview(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless(array_key_exists($key, ActionReminderRule::DEFAULTS), 404, 'Ação desconhecida.');

        $d = ActionReminderRule::DEFAULTS[$key];
        $html = \App\Services\HelpDeskMailComposer::composeSimple(
            $d['title'],
            'Lembrete: há itens pendentes aguardando sua ação.',
            \App\Services\HelpDeskMailFooter::whiteLogoDataUri()
        );

        return response()->json(['data' => [
            'html'       => $html,
            'recipients' => count(self::affectedUserIds($key)),
        ]]);
    }

    private function ensureSeeded(): void
    {
        foreach (array_keys(ActionReminderRule::DEFAULTS) as $key) {
            ActionReminderRule::firstOrCreate(['key' => $key]);
        }
    }

    /**
     * IDs dos usuários que têm a ação $key não resolvida AGORA. Reusado pelo comando de lembrete.
     * Mesma definição de "pendência" usada em ApprovalController::homeActions.
     */
    public static function affectedUserIds(string $key): array
    {
        switch ($key) {
            case 'fix_ts_adjust':
                return Timesheet::where('status', Timesheet::STATUS_ADJUSTMENT_REQUESTED)
                    ->whereNull('deleted_at')->distinct()->pluck('user_id')->filter()->values()->all();

            case 'fix_ts_rejected':
                return Timesheet::where('status', Timesheet::STATUS_REJECTED)->whereNull('deleted_at')
                    ->get(['user_id', 'date'])
                    ->filter(fn ($t) => self::withinRejectionWindow($t->date))
                    ->pluck('user_id')->unique()->filter()->values()->all();

            case 'fix_exp':
                return Expense::where('status', Expense::STATUS_ADJUSTMENT_REQUESTED)
                    ->distinct()->pluck('user_id')->filter()->values()->all();

            case 'fix_exp_rejected':
                return Expense::where('status', Expense::STATUS_REJECTED)
                    ->distinct()->pluck('user_id')->filter()->values()->all();

            case 'approve_exp':
                // Quem APROVA despesa = coordenadores (e admin), escopados aos projetos que coordenam.
                $expProjectIds = Expense::where('status', Expense::STATUS_PENDING)
                    ->distinct()->pluck('project_id')->filter()->all();
                if (empty($expProjectIds)) return [];

                $expIds = collect();
                Project::whereIn('id', $expProjectIds)->with(['coordinators:id', 'serviceType:id,code'])
                    ->get(['id', 'service_type_id', 'kanban_coordinator_override_id'])
                    ->each(function (Project $p) use ($expIds) {
                        $p->coordinators->each(fn ($c) => $expIds->push($c->id));
                        if ($p->kanban_coordinator_override_id) $expIds->push($p->kanban_coordinator_override_id);
                    });
                // Coordenadores de sustentação aprovam as despesas da fila sustentacao/cloud.
                $hasSustExp = Expense::where('status', Expense::STATUS_PENDING)
                    ->whereHas('project.serviceType', fn ($q) => $q->whereIn('code', ['sustentacao', 'cloud']))->exists();
                if ($hasSustExp) {
                    User::where('type', 'coordenador')->where('coordinator_type', 'sustentacao')->where('enabled', true)
                        ->pluck('id')->each(fn ($id) => $expIds->push($id));
                }
                return $expIds->unique()->filter()->values()->all();

            case 'pay_exp':
                return Expense::where('status', Expense::STATUS_APPROVED)->where('is_paid', false)->exists()
                    ? self::administrativos() : [];

            case 'approve_ts':
                // Apenas projetos (a fila de sustentação tem regra própria: approve_ts_sust).
                $projectIds = Timesheet::where('status', Timesheet::STATUS_PENDING)->whereNull('deleted_at')
                    ->distinct()->pluck('project_id')->filter()->all();
                if (empty($projectIds)) return [];

                $ids = collect();
                Project::whereIn('id', $projectIds)->with(['coordinators:id', 'serviceType:id,code'])
                    ->get(['id', 'service_type_id', 'kanban_coordinator_override_id'])
                    ->each(function (Project $p) use ($ids) {
                        if (in_array($p->serviceType->code ?? '', ['sustentacao', 'cloud'], true)) return; // fila sustentação → regra própria
                        $p->coordinators->each(fn ($c) => $ids->push($c->id));
                        if ($p->kanban_coordinator_override_id) $ids->push($p->kanban_coordinator_override_id);
                    });

                return $ids->unique()->filter()->values()->all();

            case 'approve_ts_sust':
                // Sustentação: só os apontamentos do DIA ANTERIOR, nos projetos sustentacao/cloud.
                $hasSust = Timesheet::where('status', Timesheet::STATUS_PENDING)->whereNull('deleted_at')
                    ->whereDate('date', now()->subDay()->toDateString())
                    ->whereHas('project.serviceType', fn ($q) => $q->whereIn('code', ['sustentacao', 'cloud']))->exists();
                if (!$hasSust) return [];

                return User::where('type', 'coordenador')->where('coordinator_type', 'sustentacao')->where('enabled', true)
                    ->pluck('id')->all();

            default:
                return [];
        }
    }

    private static function administrativos(): array
    {
        return User::where('type', 'administrativo')->where('enabled', true)->pluck('id')->all();
    }

    /** Rejeitado ainda dentro da janela (até o 5º dia útil do mês posterior à competência). */
    private static function withinRejectionWindow(?Carbon $date): bool
    {
        if (!$date) return true;
        $next = $date->copy()->addMonthNoOverflow()->startOfMonth();
        return now()->startOfDay()->lte(self::nthBusinessDayOfMonth($next->year, $next->month, 5));
    }

    private static function nthBusinessDayOfMonth(int $year, int $month, int $n): Carbon
    {
        $d = Carbon::create($year, $month, 1)->startOfDay();
        $count = 0; $last = $d->copy();
        while ($d->month === $month) {
            if (!$d->isWeekend()) { $count++; $last = $d->copy(); if ($count === $n) return $d->copy(); }
            $d->addDay();
        }
        return $last;
    }
}
