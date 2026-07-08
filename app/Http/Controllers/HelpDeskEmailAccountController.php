<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskEmailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Help Desk — Contas de E-mail (recebimento). CRUD + teste de conexão. */
class HelpDeskEmailAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = HelpDeskEmailAccount::query()
            ->with('team:id,name')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) =>
                $w->where('name', 'ilike', '%' . $request->search . '%')->orWhere('email', 'ilike', '%' . $request->search . '%')))
            ->orderBy('name')->get();
        return response()->json(['data' => $rows]); // password fica oculto pelo $hidden
    }

    private function rules(bool $creating): array
    {
        return [
            'name'            => ($creating ? 'required' : 'sometimes') . '|string|max:120',
            'email'           => ($creating ? 'required' : 'sometimes') . '|email|max:190',
            'brand'           => 'nullable|string|max:120',
            'provider'        => 'nullable|in:imap,microsoft365',
            'receive_enabled' => 'nullable|boolean',
            'protocol'        => 'nullable|in:imap,pop3',
            'host'            => 'nullable|string|max:190',
            'port'            => 'nullable|integer|min:1|max:65535',
            'encryption'      => 'nullable|in:ssl,tls,none',
            'username'        => 'nullable|string|max:190',
            'password'        => 'nullable|string|max:500', // write-only; vazio no update = mantém
            'inbox'           => 'nullable|string|max:60',
            'smtp_host'       => 'nullable|string|max:190',
            'smtp_port'       => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|in:ssl,tls,none',
            'settings'        => 'nullable|array',
            'default_team_id' => 'nullable|exists:helpdesk_teams,id',
            'enabled'         => 'nullable|boolean',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate($this->rules(true));
        $acc = HelpDeskEmailAccount::create($v);
        return response()->json(['data' => $acc->fresh()->load('team:id,name')], 201);
    }

    public function update(Request $request, HelpDeskEmailAccount $emailAccount): JsonResponse
    {
        $v = $request->validate($this->rules(false));
        if (blank($v['password'] ?? null)) unset($v['password']); // não sobrescreve se veio vazio
        $emailAccount->update($v);
        return response()->json(['data' => $emailAccount->fresh()->load('team:id,name')]);
    }

    public function destroy(HelpDeskEmailAccount $emailAccount): JsonResponse
    {
        $emailAccount->delete();
        return response()->json(null, 204);
    }

    /**
     * Testa a conexão com o servidor (alcance + handshake). Usa a extensão IMAP quando
     * disponível (login real); senão, abre um socket SSL/TLS p/ validar host:porta.
     */
    public function test(Request $request, HelpDeskEmailAccount $emailAccount): JsonResponse
    {
        [$ok, $error] = $this->attemptConnection($emailAccount);
        $emailAccount->update([
            'last_status'     => $ok ? 'connected' : 'failed',
            'last_error'      => $ok ? null : $error,
            'last_checked_at' => now(),
        ]);
        return response()->json(['data' => ['connection_status' => $emailAccount->fresh()->connection_status, 'error' => $error]]);
    }

    private function attemptConnection(HelpDeskEmailAccount $acc): array
    {
        // Provedor OAuth2 / Microsoft Graph (Exchange Online / Office 365). Conexão real,
        // app-only, sem senha — a Microsoft desativou o Basic Auth IMAP nessas caixas.
        if ($acc->provider === 'microsoft365') {
            [$ok, $error] = \App\Services\GraphMailReader::inboxStatus((string) $acc->email);
            return [$ok, $error];
        }

        $host = $acc->host; $port = $acc->port ?: ($acc->encryption === 'ssl' ? 993 : 143);
        if (!$host) return [false, 'Servidor (host) não informado.'];

        // 1) Login real via extensão IMAP, se disponível.
        if ($acc->protocol === 'imap' && function_exists('imap_open')) {
            $enc = $acc->encryption === 'ssl' ? '/ssl' : ($acc->encryption === 'tls' ? '/tls' : '/notls');
            $mailbox = "{{$host}:{$port}/imap{$enc}/novalidate-cert}INBOX";
            $mb = @imap_open($mailbox, (string) $acc->username, (string) $acc->password, 0, 1);
            if ($mb) { @imap_close($mb); return [true, null]; }
            return [false, imap_last_error() ?: 'Falha no login IMAP.'];
        }

        // 2) Fallback: alcance do servidor (socket SSL/TLS).
        $prefix = $acc->encryption === 'ssl' ? 'ssl://' : '';
        $conn = @fsockopen($prefix . $host, (int) $port, $errno, $errstr, 6);
        if ($conn) { fclose($conn); return [true, null]; }
        return [false, trim("$errstr ($errno)") ?: 'Não foi possível conectar ao servidor.'];
    }
}
