<?php

namespace App\Http\Controllers;

use App\Models\ClicksignWebhookEvent;
use App\Services\Clicksign\ClicksignService;
use App\Services\Clicksign\ClicksignWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook PÚBLICO da Clicksign (v3) — Fase 4.3.
 *
 * Segurança: HMAC obrigatório (Content-Hmac) → 401 se inválido/secret ausente.
 * Idempotência: persiste em clicksign_webhook_events por event_id; duplicado → 200 sem reprocessar
 * (suporta retries da Clicksign). NÃO baixa documentos — apenas atualiza estados + auditoria.
 */
class ClicksignWebhookController extends Controller
{
    public function handle(Request $request, ClicksignWebhookProcessor $processor): JsonResponse
    {
        $raw = $request->getContent();
        $assinatura = (string) $request->header('Content-Hmac', '');

        // 1) Autenticidade (HMAC).
        if (!ClicksignService::validarWebhook($raw, $assinatura)) {
            return response()->json(['status' => 'error', 'message' => 'Assinatura inválida'], 401);
        }

        $payload = json_decode($raw, true) ?: [];
        if (!is_array($payload) || empty($payload)) {
            return response()->json(['status' => 'error', 'message' => 'Payload inválido'], 400);
        }

        // 2) Idempotência por event_id (retry envia payload idêntico → mesmo hash).
        $eventId = $payload['event']['id'] ?? hash('sha256', $raw);
        $log = ClicksignWebhookEvent::firstOrCreate(
            ['event_id' => $eventId],
            [
                'event_name'            => $processor->eventName($payload),
                'clicksign_envelope_id' => $processor->extractEnvelopeId($payload),
                'payload'               => $payload,
            ],
        );
        if ($log->processed_at !== null) {
            return response()->json(['status' => 'duplicate'], 200); // já processado
        }

        // 3) Processa (estados + auditoria). Falha NÃO marca processed_at → permite retry da Clicksign.
        try {
            $processor->process($payload, $log);
            $log->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('Clicksign webhook: falha ao processar', ['event_id' => $eventId, 'erro' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Falha ao processar'], 500);
        }

        return response()->json(['status' => 'ok'], 200);
    }
}
