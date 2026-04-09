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
     * Dispara sincronização manual via service (output rico para debug).
     * Aceita parâmetro opcional `since` (ISO 8601).
     */
    public function sync(Request $request, MovideskService $service): JsonResponse
    {
        $sinceInput = $request->input('since');

        if (!config('services.movidesk.token')) {
            return response()->json([
                'success' => false,
                'message' => 'MOVIDESK_API_TOKEN não configurado no servidor.',
            ], 422);
        }

        try {
            // Resolver `since`
            if ($sinceInput) {
                $since = Carbon::parse($sinceInput);
            } else {
                $lastSync    = SystemSetting::get('movidesk_last_sync');
                $minLookback = now()->subHours(48);

                if ($lastSync) {
                    $fromLastSync = Carbon::parse($lastSync)->subMinutes(20);
                    $since = $fromLastSync->lt($minLookback) ? $fromLastSync : $minLookback;
                } else {
                    $since = now()->subHours(24);
                }
            }

            Log::info('🔧 [MOVIDESK ADMIN] Sync manual iniciado', [
                'since'        => $since->toIso8601String(),
                'triggered_by' => auth()->user()?->email,
            ]);

            $lines   = [];
            $lines[] = "Buscando desde: {$since->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s')}";

            $tickets      = $service->fetchTicketsSince($since);
            $ticketCount  = count($tickets);
            $lines[]      = "{$ticketCount} ticket(s) encontrado(s)";

            $totalCreated = 0;

            foreach ($tickets as $ticketData) {
                $ticketId   = $ticketData['id'] ?? '?';
                $ticketData = $service->fetchTicket((int) $ticketId);

                if (!$ticketData) {
                    $lines[] = "  ⚠ Ticket #{$ticketId}: falha ao buscar detalhes";
                    continue;
                }

                $created       = $service->processTicket($ticketData);
                $totalCreated += $created;

                if ($created > 0) {
                    $lines[] = "  ✓ Ticket #{$ticketId}: {$created} apontamento(s) importado(s)";
                } else {
                    $lines[] = "  - Ticket #{$ticketId}: sem novos apontamentos";
                }
            }

            $lines[] = "---";
            $lines[] = "Total importado: {$totalCreated}";

            SystemSetting::set('movidesk_last_sync', now()->toIso8601String(), 'string', 'movidesk');
            $lastSync = SystemSetting::get('movidesk_last_sync');

            return response()->json([
                'success'         => true,
                'message'         => 'Sincronização concluída.',
                'output'          => implode("\n", $lines),
                'tickets_found'   => $ticketCount,
                'created'         => $totalCreated,
                'last_sync'       => $lastSync,
                'last_sync_human' => $lastSync
                    ? Carbon::parse($lastSync)->timezone('America/Sao_Paulo')->format('d/m/Y H:i')
                    : null,
                'today_imported'  => Timesheet::where('origin', 'webhook')->whereDate('created_at', today())->count(),
                'total_imported'  => Timesheet::where('origin', 'webhook')->count(),
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
