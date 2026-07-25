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

    /**
     * Callback do OAuth (público): troca o code por tokens e redireciona pro front.
     * Fluxo `vault` (step-up do Cofre) devolve uma página mínima que entrega o token
     * ao opener via postMessage e fecha o popup — o token nunca passa por URL.
     */
    public function callback(Request $request): RedirectResponse|\Illuminate\Http\Response
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

            $isVault = ($payload['flow'] ?? '') === 'vault';

            $tok = MicrosoftCalendarService::exchangeCode($code);
            if (!empty($tok['error']) || empty($tok['access_token'])) {
                return $isVault
                    ? $this->vaultPopupResponse()
                    : redirect()->away($front . '?outlook=error');
            }

            // Fluxo do Cofre (step-up): identifica a conta e grava o step-up server-side.
            // O FE detecta via POLL em /vault/ms/status — NÃO grava integração de Outlook.
            if ($isVault) {
                \App\Services\VaultStepUp::completeFromTokens($uid, $tok);
                return $this->vaultPopupResponse();
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

    /**
     * Página do popup do step-up do Cofre. O FE detecta a conclusão por POLL
     * (/vault/ms/status), então aqui só sinalizamos e tentamos fechar (best-effort:
     * postMessage + window.close podem ser bloqueados por COOP, mas o poll cobre).
     */
    private function vaultPopupResponse(): \Illuminate\Http\Response
    {
        $origin = json_encode(rtrim((string) config('app.frontend_url'), '/'));
        $html = <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Minutor — Cofre</title>
<style>
  :root { color-scheme: light dark; }
  * { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body {
    display: flex; align-items: center; justify-content: center;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, system-ui, sans-serif;
    background: #f1f5f9; color: #0f172a; padding: 24px;
  }
  .card {
    width: 100%; max-width: 380px; background: #fff; border: 1px solid #e2e8f0;
    border-radius: 20px; padding: 40px 32px; text-align: center;
    box-shadow: 0 10px 30px -12px rgba(15,23,42,.18);
  }
  .badge {
    width: 64px; height: 64px; margin: 0 auto 20px; border-radius: 50%;
    background: #dcfce7; display: flex; align-items: center; justify-content: center;
  }
  .badge svg { width: 32px; height: 32px; stroke: #16a34a; }
  h1 { font-size: 19px; margin: 0 0 8px; font-weight: 650; }
  p { font-size: 14px; line-height: 1.5; color: #64748b; margin: 0 0 24px; }
  button {
    width: 100%; padding: 12px 16px; font-size: 15px; font-weight: 600;
    color: #fff; background: #0f766e; border: 0; border-radius: 12px; cursor: pointer;
    transition: background .15s;
  }
  button:hover { background: #115e59; }
  .hint { font-size: 12px; color: #94a3b8; margin: 14px 0 0; }
  @media (prefers-color-scheme: dark) {
    body { background: #0b1220; color: #e2e8f0; }
    .card { background: #111a2e; border-color: #1e293b; box-shadow: 0 10px 30px -12px rgba(0,0,0,.5); }
    .badge { background: rgba(22,163,74,.15); }
    p { color: #94a3b8; }
  }
</style>
</head>
<body>
  <div class="card">
    <div class="badge">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
    </div>
    <h1>Verificação concluída</h1>
    <p>Sua identidade foi confirmada com a Microsoft.<br>Pode fechar esta janela e voltar ao cofre.</p>
    <button type="button" onclick="window.close()">Fechar janela</button>
    <p class="hint">Se a janela não fechar, feche-a manualmente — o cofre já detectou a verificação.</p>
  </div>
  <script>
    try { window.opener && window.opener.postMessage({type:'vault-ms-stepup'}, {$origin}); } catch (e) {}
  </script>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Desconecta (remove a integração) — permitido a qualquer momento. */
    public function disconnect(Request $request): JsonResponse
    {
        $this->integration($request)?->delete();
        return response()->json(['data' => ['connected' => false]]);
    }

    /** Re-sincroniza a agenda do usuário (botão "Sincronizar") e guarda o snapshot no cache. */
    public function sync(Request $request): JsonResponse
    {
        $i = $this->integration($request);
        if (!$i) return response()->json(['message' => 'Outlook não conectado.'], 422);

        $synced = MicrosoftCalendarService::syncEvents($i);
        if ($synced === null) return response()->json(['message' => 'Não foi possível renovar o acesso. Reconecte sua conta Microsoft.'], 422);

        return response()->json(['data' => [
            'synced'       => $synced,
            'last_sync_at' => $i->last_sync_at->toIso8601String(),
        ]]);
    }

    private function integration(Request $request): ?UserIntegration
    {
        $u = $request->user();
        abort_unless($u, 401);
        return UserIntegration::where('user_id', $u->id)->where('provider', self::PROVIDER)->first();
    }
}
