<?php

namespace App\Http\Controllers;

use App\Models\ConsultantHourBankClosing;
use App\Models\User;
use App\Services\HourBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsultantHourBankController extends Controller
{
    public function __construct(private HourBankService $service) {}

    // ─── Preview do mês atual ou específico ───────────────────────────────

    /**
     * GET /consultant-hour-bank/{userId}/preview[?year_month=YYYY-MM]
     * Cálculo dinâmico (não persiste). Se fechado, retorna snapshot.
     */
    public function preview(Request $request, int $userId): JsonResponse
    {
        try {
            $user = User::findOrFail($userId);
            $dailyHours = (float) ($request->input('daily_hours', $user->daily_hours ?? 8.0));
            $yearMonth  = $request->input('year_month');

            if ($yearMonth) {
                $data = $this->service->previewMonth($userId, $yearMonth, $dailyHours);
            } else {
                $data = $this->service->previewCurrentMonth($userId, $dailyHours);
            }

            $data['user'] = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ─── Histórico do consultor ───────────────────────────────────────────

    /**
     * GET /consultant-hour-bank/{userId}/history
     */
    public function history(Request $request, int $userId): JsonResponse
    {
        try {
            User::findOrFail($userId);
            $limit   = (int) $request->input('limit', 24);
            $history = $this->service->getHistory($userId, $limit);
            return response()->json(['items' => $history]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ─── Fechar mês ───────────────────────────────────────────────────────

    /**
     * POST /consultant-hour-bank/{userId}/close
     * Body: { year_month, daily_hours?, notes? }
     */
    public function close(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'year_month'  => 'required|string|regex:/^\d{4}-\d{2}$/',
            'daily_hours' => 'nullable|numeric|min:1|max:24',
            'notes'       => 'nullable|string|max:1000',
        ]);

        try {
            User::findOrFail($userId);
            $closing = $this->service->closeMonth(
                $userId,
                $validated['year_month'],
                auth()->id(),
                (float) ($validated['daily_hours'] ?? 8.0),
                $validated['notes'] ?? null
            );
            return response()->json($closing);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ─── Reabrir mês ─────────────────────────────────────────────────────

    /**
     * POST /consultant-hour-bank/{userId}/reopen
     * Body: { year_month }
     */
    public function reopen(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'year_month' => 'required|string|regex:/^\d{4}-\d{2}$/',
        ]);

        try {
            User::findOrFail($userId);
            $closing = $this->service->reopenMonth($userId, $validated['year_month']);
            return response()->json($closing);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ─── Lista consultores com saldo atual ────────────────────────────────

    /**
     * GET /consultant-hour-bank/consultants
     * Retorna usuários com role Consultor e o saldo atual do banco de horas.
     */
    public function consultants(Request $request): JsonResponse
    {
        $users = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Consultor', 'Consultant']))
            ->where('enabled', true)
            ->select('id', 'name', 'email', 'daily_hours')
            ->orderBy('name')
            ->get();

        $now = now();
        $result = $users->map(function ($user) use ($now) {
            // Pega o saldo final mais recente (mês anterior fechado)
            $lastClosing = ConsultantHourBankClosing::where('user_id', $user->id)
                ->where('status', 'closed')
                ->orderBy('year_month', 'desc')
                ->first();

            return [
                'id'             => $user->id,
                'name'           => $user->name,
                'email'          => $user->email,
                'daily_hours'    => $user->daily_hours ?? 8.0,
                'current_balance'=> $lastClosing ? (float) $lastClosing->final_balance : 0.0,
                'last_closed'    => $lastClosing?->year_month,
            ];
        });

        return response()->json(['items' => $result]);
    }
}
