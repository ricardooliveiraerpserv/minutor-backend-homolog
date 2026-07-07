<?php

namespace App\Http\Controllers;

use App\Models\UserIntegration;
use App\Services\MicrosoftCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Integração Microsoft 365 / Outlook por usuário (OAuth2 delegado). 1 integração por usuário.
 * Defensivo: falhas viram mensagem amigável; nunca travam a UI. Tokens criptografados no model.
 */
class UserIntegrationController extends Controller
{
    private const PROVIDER = 'microsoft';

    /** Estado da integração p/ o card. */
    public function status(Request $request): JsonResponse
    {
        $i = $this->integration($request);
        return response()->json(['data' => [
            'configured'    => MicrosoftCalendarService::configured(),
            'connected'     => (bool) $i,
            'account_email' => $i?->account_email,
            'connected_at'  => $i?->connected_at?->toIso8601String(),
            'last_sync_at'  => $i?->last_sync_at?->toIso8601String(),
        ]]);
    }

    /** Inicia o OAuth: devolve a URL de consentimento (o front redireciona). */
    public function connect(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        if (!MicrosoftCalendarService::configured()) {
            return response()->json(['message' => 'Integração com a Microsoft não está configurada no servidor.'], 422);
        }
        // state assinado (criptografado) amarra o callback a este usuário.
        $state = Crypt::encryptString(json_encode(['uid' => $u->id, 't' => now()->timestamp]));
        return response()->json(['data' => ['authorize_url' => MicrosoftCalendarService::authorizeUrl($state)]]);
    }

    /** Callback do OAuth (público): troca o code por tokens e redireciona pro front. */
    public function callback(Request $request): RedirectResponse
    {
        $front = rtrim((string) config('app.frontend_url'), '/') . '/inicio';

        if ($request->filled('error')) {
            return redirect()->away($front . '?outlook=error');
        }

        try {
            $payload = json_decode(Crypt::decryptString((string) $request->query('state')), true);
            $uid = (int) ($payload['uid'] ?? 0);
            $code = (string) $request->query('code');
            abort_if($uid <= 0 || $code === '', 400);

            $tok = MicrosoftCalendarService::exchangeCode($code);
            if (!empty($tok['error']) || empty($tok['access_token'])) {
                return redirect()->away($front . '?outlook=error');
            }

            UserIntegration::updateOrCreate(
                ['user_id' => $uid, 'provider' => self::PROVIDER],
                [
                    'access_token'  => $tok['access_token'],
                    'refresh_token' => $tok['refresh_token'] ?? null,
                    'account_email' => MicrosoftCalendarService::me($tok['access_token']),
                    'expires_at'    => now()->addSeconds((int) ($tok['expires_in'] ?? 3600)),
                    'connected_at'  => now(),
                ]
            );

            return redirect()->away($front . '?outlook=connected');
        } catch (\Throwable $e) {
            Log::warning('Outlook OAuth callback falhou', ['e' => $e->getMessage()]);
            return redirect()->away($front . '?outlook=error');
        }
    }

    /** Desconecta (remove a integração) — permitido a qualquer momento. */
    public function disconnect(Request $request): JsonResponse
    {
        $this->integration($request)?->delete();
        return response()->json(['data' => ['connected' => false]]);
    }

    /** Sincroniza os eventos do mês atual e guarda no cache. */
    public function sync(Request $request): JsonResponse
    {
        $i = $this->integration($request);
        if (!$i) return response()->json(['message' => 'Outlook não conectado.'], 422);

        $token = $this->freshToken($i);
        if (!$token) return response()->json(['message' => 'Não foi possível renovar o acesso. Reconecte sua conta Microsoft.'], 422);

        $start = now()->startOfMonth();
        $end   = now()->endOfMonth();
        $events = MicrosoftCalendarService::fetchEvents($token, $start, $end);

        $i->update(['cached_events' => $events, 'last_sync_at' => now()]);

        return response()->json(['data' => [
            'synced'       => count($events),
            'last_sync_at' => $i->last_sync_at->toIso8601String(),
        ]]);
    }

    /** Garante um access_token válido (renova via refresh_token se expirado). Persiste o novo. */
    private function freshToken(UserIntegration $i): ?string
    {
        if (!$i->isExpired() && $i->access_token) return $i->access_token;
        if (!$i->refresh_token) return null;

        $tok = MicrosoftCalendarService::refresh($i->refresh_token);
        if (!empty($tok['error']) || empty($tok['access_token'])) return null;

        $i->update([
            'access_token'  => $tok['access_token'],
            'refresh_token' => $tok['refresh_token'] ?? $i->refresh_token, // MS pode rotacionar
            'expires_at'    => now()->addSeconds((int) ($tok['expires_in'] ?? 3600)),
        ]);
        return $tok['access_token'];
    }

    private function integration(Request $request): ?UserIntegration
    {
        $u = $request->user();
        abort_unless($u, 401);
        return UserIntegration::where('user_id', $u->id)->where('provider', self::PROVIDER)->first();
    }
}
