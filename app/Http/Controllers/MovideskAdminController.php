<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\Timesheet;
use App\Services\MovideskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class MovideskAdminController extends Controller
{
    /**
     * Retorna o status da integração Movidesk:
     * - data/hora da última sincronização
     * - total de timesheets importados via webhook
     */
    public function status(): JsonResponse
    {
        $lastSync = SystemSetting::get('movidesk_last_sync');

        $totalImported = Timesheet::where('origin', 'webhook')->count();

        $todayImported = Timesheet::where('origin', 'webhook')
            ->whereDate('created_at', today())
            ->count();

        return response()->json([
            'last_sync'      => $lastSync,
            'last_sync_human'=> $lastSync ? Carbon::parse($lastSync)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : null,
            'total_imported' => $totalImported,
            'today_imported' => $todayImported,
            'token_configured' => !empty(config('services.movidesk.token')),
        ]);
    }

    /**
     * Dispara sincronização manual.
     * Aceita parâmetro opcional `since` (ISO 8601).
     * Sem `since` → usa o mesmo fallback do command (lastSync - 20min ou 24h).
     */
    public function sync(Request $request): JsonResponse
    {
        $since = $request->input('since');

        if (!config('services.movidesk.token')) {
            return response()->json([
                'success' => false,
                'message' => 'MOVIDESK_API_TOKEN não configurado no servidor.',
            ], 422);
        }

        try {
            Log::info('🔧 [MOVIDESK ADMIN] Sync manual iniciado', [
                'since'     => $since,
                'triggered_by' => auth()->user()?->email,
            ]);

            $params = $since ? ['--since' => $since] : [];
            Artisan::call('movidesk:sync', $params);

            $output = Artisan::output();

            // Ler resultado após execução
            $lastSync = SystemSetting::get('movidesk_last_sync');

            return response()->json([
                'success'         => true,
                'message'         => 'Sincronização concluída.',
                'output'          => trim($output),
                'last_sync'       => $lastSync,
                'last_sync_human' => $lastSync
                    ? Carbon::parse($lastSync)->timezone('America/Sao_Paulo')->format('d/m/Y H:i')
                    : null,
            ]);

        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK ADMIN] Erro no sync manual', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao executar sincronização: ' . $e->getMessage(),
            ], 500);
        }
    }
}
