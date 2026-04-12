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

    // ─── Range: todos os meses em cadeia ─────────────────────────────────

    /**
     * GET /consultant-hour-bank/{userId}/range?year_month=YYYY-MM
     *
     * Retorna:
     *   current  → dados calculados do mês solicitado (ou mês atual se omitido)
     *   history  → meses anteriores ao current, do mais recente ao mais antigo
     */
    public function range(Request $request, int $userId): JsonResponse
    {
        try {
            $user       = User::findOrFail($userId);
            $dailyHours = (float) ($user->daily_hours ?? 8.0);
            $startDate  = $user->bank_hours_start_date
                ? \Carbon\Carbon::parse($user->bank_hours_start_date)->format('Y-m-d')
                : null;

            // Mês solicitado (default: mês atual)
            $toYearMonth = $request->input('year_month', \Carbon\Carbon::now()->format('Y-m'));

            // Mês de início do banco
            $fromYearMonth = $startDate
                ? \Carbon\Carbon::parse($startDate)->format('Y-m')
                : $toYearMonth;

            // Garante que from <= to
            if ($fromYearMonth > $toYearMonth) {
                $fromYearMonth = $toYearMonth;
            }

            $all = $this->service->calculateRange(
                $userId, $fromYearMonth, $toYearMonth, $dailyHours, $startDate
            );

            $months  = array_values($all);
            $current = end($months) ?: null;
            // History = todos menos o último (o mês atual), do mais recente ao mais antigo
            $history = array_reverse(array_slice($months, 0, -1));

            return response()->json([
                'current' => $current,
                'history' => $history,
                'bank_hours_start_date' => $startDate,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ─── Preview do mês atual ou específico ───────────────────────────────

    /**
     * GET /consultant-hour-bank/{userId}/preview[?year_month=YYYY-MM]
     */
    public function preview(Request $request, int $userId): JsonResponse
    {
        try {
            $user       = User::findOrFail($userId);
            $dailyHours = (float) ($request->input('daily_hours', $user->daily_hours ?? 8.0));
            $yearMonth  = $request->input('year_month');
            $startDate  = $user->bank_hours_start_date
                ? \Carbon\Carbon::parse($user->bank_hours_start_date)->format('Y-m-d')
                : null;

            if ($yearMonth) {
                $data = $this->service->previewMonth($userId, $yearMonth, $dailyHours, $startDate);
            } else {
                $data = $this->service->previewCurrentMonth($userId, $dailyHours, $startDate);
            }

            $data['user']                  = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
            $data['bank_hours_start_date'] = $startDate;
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
            $user      = User::findOrFail($userId);
            $limit     = (int) $request->input('limit', 24);
            $startDate = $user->bank_hours_start_date
                ? \Carbon\Carbon::parse($user->bank_hours_start_date)->format('Y-m-d')
                : null;

            $history = $this->service->getHistory($userId, $limit, $startDate);
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
            $user      = User::findOrFail($userId);
            $startDate = $user->bank_hours_start_date
                ? \Carbon\Carbon::parse($user->bank_hours_start_date)->format('Y-m-d')
                : null;

            $closing = $this->service->closeMonth(
                $userId,
                $validated['year_month'],
                auth()->id(),
                (float) ($validated['daily_hours'] ?? $user->daily_hours ?? 8.0),
                $validated['notes'] ?? null,
                $startDate
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
     */
    public function consultants(Request $request): JsonResponse
    {
        $users = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Consultor', 'Consultant']))
            ->where('enabled', true)
            ->select('id', 'name', 'email', 'daily_hours', 'bank_hours_start_date')
            ->orderBy('name')
            ->get();

        $now = now();
        $result = $users->map(function ($user) use ($now) {
            $lastClosing = ConsultantHourBankClosing::where('user_id', $user->id)
                ->where('status', 'closed')
                ->orderBy('year_month', 'desc')
                ->first();

            return [
                'id'                    => $user->id,
                'name'                  => $user->name,
                'email'                 => $user->email,
                'daily_hours'           => $user->daily_hours ?? 8.0,
                'bank_hours_start_date' => $user->bank_hours_start_date
                    ? \Carbon\Carbon::parse($user->bank_hours_start_date)->format('Y-m-d')
                    : null,
                'current_balance'       => $lastClosing ? (float) $lastClosing->final_balance : 0.0,
                'last_closed'           => $lastClosing?->year_month,
            ];
        });

        return response()->json(['items' => $result]);
    }
}
