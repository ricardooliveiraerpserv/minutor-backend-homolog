<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\Timesheet;
use App\Services\MovideskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
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
     * Dispara sincronização manual via service (output rico para debug).
     * Aceita parâmetro opcional `since` (ISO 8601).
     */
    public function sync(Request $request): JsonResponse
    {
        $sinceInput = $request->input('since');

        if (!config('services.movidesk.token')) {
            return response()->json([
                'success' => false,
                'message' => 'MOVIDESK_API_TOKEN não configurado no servidor.',
            ], 422);
        }

        // Resolver `since`
        // Manual sync usa janela de 2h para não estourar timeout HTTP (504).
        // O cron cobre janela de 48h em background sem timeout.
        if ($sinceInput) {
            $since = Carbon::parse($sinceInput);
        } else {
            $lastSync     = SystemSetting::get('movidesk_last_sync');
            $maxLookback  = now()->subHours(2); // limite para evitar 504 no HTTP

            if ($lastSync) {
                $fromLastSync = Carbon::parse($lastSync)->subMinutes(20);
                // Usa o mais antigo entre last_sync-20min e now-2h, mas não mais que 2h
                $since = $fromLastSync->lt($maxLookback) ? $maxLookback : $fromLastSync;
            } else {
                $since = $maxLookback;
            }
        }

        Log::info('🔧 [MOVIDESK ADMIN] Sync manual iniciado', [
            'since'        => $since->toIso8601String(),
            'triggered_by' => auth()->user()?->email,
        ]);

        try {
            $params = ['--since' => $since->toIso8601String()];
            Artisan::call('movidesk:sync', $params);
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            Log::error('🚨 [MOVIDESK ADMIN] Erro no sync manual', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao executar sincronização: ' . $e->getMessage(),
            ], 500);
        }

        $lastSync = SystemSetting::get('movidesk_last_sync');

        return response()->json([
            'success'         => true,
            'message'         => 'Sincronização concluída.',
            'output'          => $output ?: "Sync concluído desde: {$since->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s')}",
            'last_sync'       => $lastSync,
            'last_sync_human' => $lastSync
                ? Carbon::parse($lastSync)->timezone('America/Sao_Paulo')->format('d/m/Y H:i')
                : null,
            'today_imported'  => Timesheet::where('origin', 'webhook')->whereDate('created_at', today())->count(),
            'total_imported'  => Timesheet::where('origin', 'webhook')->count(),
        ]);
    }

    /**
     * Diagnóstico: chama a API Movidesk e retorna a resposta bruta para debug.
     * GET /api/v1/movidesk/debug
     */
    public function debug(Request $request): JsonResponse
    {
        $token = config('services.movidesk.token');

        if (!$token) {
            return response()->json(['error' => 'Token não configurado'], 422);
        }

        $since = $request->input('since', now()->subHours(48)->utc()->format('Y-m-d\TH:i:s'));

        // Testa 3 formatos de filtro diferentes
        $tests = [];

        $formats = [
            'bare'     => "lastUpdate gt {$since}",
            'with_Z'   => "lastUpdate gt {$since}Z",
            'datetime' => "lastUpdate gt datetime'{$since}'",
        ];

        foreach ($formats as $name => $filter) {
            $url = 'https://api.movidesk.com/public/v1/tickets'
                . '?token=' . urlencode($token)
                . '&$filter=' . urlencode($filter)
                . '&$select=id,lastUpdate'
                . '&$top=5';

            try {
                $resp = Http::timeout(15)->get($url);
                $tests[$name] = [
                    'filter'  => $filter,
                    'url'     => $url,
                    'status'  => $resp->status(),
                    'body'    => $resp->body(),
                ];
            } catch (\Throwable $e) {
                $tests[$name] = ['error' => $e->getMessage()];
            }
        }

        // Busca o ticket mais recente com expand completo para ver estrutura real
        try {
            $urlFull = 'https://api.movidesk.com/public/v1/tickets'
                . '?token=' . urlencode($token)
                . '&$expand=clients,owner,actions($expand=timeAppointments)'
                . '&$top=1'
                . '&$orderby=lastUpdate desc';
            $resp = Http::timeout(20)->get($urlFull);
            $tests['latest_full'] = [
                'status' => $resp->status(),
                'body'   => $resp->json(),
            ];
        } catch (\Throwable $e) {
            $tests['latest_full'] = ['error' => $e->getMessage()];
        }

        return response()->json($tests);
    }
}
