<?php

namespace App\Http\Controllers;

use App\Services\UsageTelemetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telemetria de uso vinda do FRONTEND (interações que o backend não enxerga sozinho:
 * abrir o Customer 360, expandir/recolher blocos, tempo dentro do painel).
 *
 * Allowlist de feature/action: a coleta é estruturada e controlada — o cliente não grava
 * eventos arbitrários. Best-effort (sempre 202).
 */
class HelpDeskUsageController extends Controller
{
    /** feature => actions permitidas. */
    private const ALLOW = [
        'customer_360' => ['viewed', 'closed', 'block_toggled'],
    ];

    public function record(Request $request, UsageTelemetry $telemetry): JsonResponse
    {
        $v = $request->validate([
            'feature'         => 'required|string|max:40',
            'action'          => 'required|string|max:40',
            'entity_type'     => 'nullable|string|max:40',
            'entity_id'       => 'nullable|integer',
            'work_session_id' => 'nullable|integer',
            'metadata'        => 'nullable|array',
        ]);

        $allowed = (self::ALLOW[$v['feature']] ?? []);
        if (!in_array($v['action'], $allowed, true)) {
            return response()->json(['ok' => false], 202); // ignora silenciosamente fora da allowlist
        }

        $telemetry->record($v['feature'], $v['action'], [
            'user_id'         => $request->user()?->id,
            'entity_type'     => $v['entity_type'] ?? null,
            'entity_id'       => $v['entity_id'] ?? null,
            'work_session_id' => $v['work_session_id'] ?? null,
            'metadata'        => $v['metadata'] ?? null,
        ]);

        return response()->json(['ok' => true], 202);
    }
}
