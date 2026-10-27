<?php

namespace App\Http\Controllers;

use App\Models\ExpenseOpenPeriod;
use App\Services\ClosingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Bloco de DESPESAS do Fechamento — libera a inclusão/edição de despesa em competência
 * fechada (após o 2º dia útil do mês seguinte). Escopo da liberação: global/cliente/
 * projeto/usuário (nulos = "qualquer"). A liberação fecha sozinha às 23:59 do dia.
 */
class ExpenseClosingController extends Controller
{
    public function __construct(private ClosingService $closing) {}

    private function canManage(): bool
    {
        $u = Auth::user();
        return $u && ($u->isAdmin() || $u->isAdministrativo());
    }

    /** Liberações ativas + prazo (2º dia útil) das competências recentes. */
    public function index(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $releases = ExpenseOpenPeriod::with([
                'customer:id,name', 'project:id,code,name', 'user:id,name', 'openedBy:id,name',
            ])
            ->whereNull('closed_at')
            ->where(fn ($q) => $q->whereNull('auto_close_at')->orWhere('auto_close_at', '>=', now()))
            ->orderByDesc('id')
            ->get()
            ->map(fn (ExpenseOpenPeriod $p) => [
                'id'            => $p->id,
                'year_month'    => $p->year_month,
                'customer'      => $p->customer?->only(['id', 'name']),
                'project'       => $p->project ? ['id' => $p->project->id, 'code' => $p->project->code, 'name' => $p->project->name] : null,
                'user'          => $p->user?->only(['id', 'name']),
                'opened_by'     => $p->openedBy?->name,
                'auto_close_at' => $p->auto_close_at?->toIso8601String(),
                'note'          => $p->note,
            ]);

        // Prazos das últimas 6 competências (para exibir Aberta/Fechada no bloco).
        $deadlines = [];
        $cursor = Carbon::now('America/Sao_Paulo')->startOfMonth();
        for ($i = 0; $i < 6; $i++) {
            $ym = $cursor->format('Y-m');
            $deadline = $this->closing->expenseDeadline($ym);
            $deadlines[] = [
                'year_month'  => $ym,
                'deadline'    => $deadline->toIso8601String(),
                'closed_now'  => Carbon::now('America/Sao_Paulo')->gt($deadline),
            ];
            $cursor->subMonthNoOverflow();
        }

        return response()->json(['releases' => $releases, 'deadlines' => $deadlines]);
    }

    /** Libera uma competência para despesas no escopo escolhido. Fecha às 23:59 do dia. */
    public function store(Request $request): JsonResponse
    {
        if (!$this->canManage()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $v = $request->validate([
            'year_month'  => 'required|date_format:Y-m',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'project_id'  => 'nullable|integer|exists:projects,id',
            'user_id'     => 'nullable|integer|exists:users,id',
            'note'        => 'nullable|string|max:500',
        ]);

        $release = ExpenseOpenPeriod::create([
            'year_month'    => $v['year_month'],
            'customer_id'   => $v['customer_id'] ?? null,
            'project_id'    => $v['project_id'] ?? null,
            'user_id'       => $v['user_id'] ?? null,
            'opened_by'     => Auth::id(),
            'auto_close_at' => Carbon::now('America/Sao_Paulo')->endOfDay(), // 23:59:59 do dia
            'note'          => $v['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'id' => $release->id], 201);
    }

    /** Fecha (encerra) uma liberação antes do auto-close. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$this->canManage()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $release = ExpenseOpenPeriod::whereKey($id)->whereNull('closed_at')->first();
        if ($release) {
            $release->update(['closed_at' => now(), 'closed_by' => Auth::id()]);
        }
        return response()->json(['ok' => true]);
    }
}
